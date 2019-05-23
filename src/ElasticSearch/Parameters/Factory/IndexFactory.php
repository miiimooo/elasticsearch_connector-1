<?php

namespace Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory;

use Drupal\elasticsearch_connector\Event\PrepareDocumentIndexEvent;
use Drupal\field\FieldConfigInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\elasticsearch_connector\Event\PrepareIndexEvent;
use Drupal\elasticsearch_connector\Event\PrepareIndexMappingEvent;
use Drupal\search_api_autocomplete\Suggester\SuggesterInterface;

/**
 * Create Elasticsearch Indices.
 */
class IndexFactory {

  /**
   * Build parameters required to index.
   *
   * TODO: We need to handle the following params as well:
   * ['consistency'] = (enum) Explicit write consistency setting for the
   * operation
   * ['refresh']     = (boolean) Refresh the index after performing the
   * operation
   * ['replication'] = (enum) Explicitly set the replication type
   * ['fields']      = (list) Default comma-separated list of fields to return
   * in the response for updates.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index to create.
   * @param bool $with_type
   *   Should the index be created with a type.
   *
   * @return array
   *   Associative array with the following keys:
   *   - index: The name of the index on the Elasticsearch server.
   *   - type(optional): The name of the type to use for the given index.
   */
  public static function index(IndexInterface $index, $with_type = FALSE) {
    $params          = [];
    $params['index'] = self::getIndexName($index);

    if ($with_type) {
      $params['type'] = $index->id();
    }

    return $params;
  }

  /**
   * Build parameters required to create an index
   * TODO: Add the timeout option.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *
   * @return array
   */
  public static function create(IndexInterface $index) {
    $indexName   = self::getIndexName($index);
    $indexConfig = [
      'index' => $indexName,
      'body'  => [
        'settings' => [
          'number_of_shards'   => $index->getOption('number_of_shards', 5),
          'number_of_replicas' => $index->getOption('number_of_replicas', 1),
        ],
      ],
    ];

    // Allow other modules to alter index config before we create it.
    $dispatcher        = \Drupal::service('event_dispatcher');
    $prepareIndexEvent = new PrepareIndexEvent($indexConfig, $indexName, $index);
    $event             = $dispatcher->dispatch(PrepareIndexEvent::PREPARE_INDEX, $prepareIndexEvent);
    $indexConfig       = $event->getIndexConfig();

    return $indexConfig;
  }

  /**
   * Build parameters to bulk delete indexes.
   *
   * @param \Drupal\search_api\IndexInterface $index
   * @param array $ids
   *
   * @return array
   */
  public static function bulkDelete(IndexInterface $index, array $ids) {
    $params = self::index($index, TRUE);
    foreach ($ids as $id) {
      $params['body'][] = [
        'delete' => [
          '_index' => $params['index'],
          '_type'  => $params['type'],
          '_id'    => $id,
        ],
      ];
    }

    return $params;
  }

  /**
   * Build parameters to bulk delete indexes.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   An array of items to be indexed, keyed by their item IDs.
   *
   * @return array
   *   Array of parameters to send along to Elasticsearch to perform the bulk
   *   index.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public static function bulkIndex(IndexInterface $index, array $items) {
    $dispatcher = \Drupal::service('event_dispatcher');
    $params     = self::index($index, TRUE);

    foreach ($items as $id => $item) {
      $data = [
        '_language' => $item->getLanguage(),
      ];

      /** @var \Drupal\search_api\Item\FieldInterface $field */
      foreach ($item as $name => $field) {
        $field_type = $field->getType();
        $values     = [];
        foreach ($field->getValues() as $value) {
          $values[] = self::getFieldValue($field_type, $value);
        }
        $data[$field->getFieldIdentifier()] = $values;
      }

//      /** @var \Drupal\search_api\Item\FieldInterface $field */
//      foreach ($item as $name => $field) {
//        $field_type = $field->getType();
//
//        // Set to list only if list.
//        $value   = NULL;
//        $is_list = self::isFieldList($index, $field);
//        if ($is_list) {
//          $value = [];
//        }
//        foreach ($field->getValues() as $val) {
//          if ($is_list) {
//            $value[] = self::getFieldValue($field_type, $val);
//          }
//          else {
//            $value = self::getFieldValue($field_type, $val);
//          }
//        }
//        $data[$field->getFieldIdentifier()] = $value;
//      }

      // Allow other modules to alter document before we create it.
      $documentIndexEvent = new PrepareDocumentIndexEvent(
        $data,
        $params['index'],
        $index
      );

      $event = $dispatcher->dispatch(
        PrepareDocumentIndexEvent::PREPARE_DOCUMENT_INDEX,
        $documentIndexEvent
      );

      $params['body'][] = ['index' => ['_id' => $id]];
      $params['body'][] = $event->getDocument();
    }

    return $params;
  }

  /**
   * Build parameters required to create an index mapping.
   *
   * TODO: We need also:
   * $params['index'] - (Required)
   * ['type'] - The name of the document type
   * ['timeout'] - (time) Explicit operation timeout.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   *
   * @return array
   *   Parameters required to create an index mapping.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function mapping(IndexInterface $index) {
    $params = self::index($index, TRUE);

    $properties = [
      'id' => [
        'type' => 'keyword',
      ],
    ];

    // Figure out which fields are used for autocompletion if any.
    if (\Drupal::moduleHandler()->moduleExists('search_api_autocomplete')) {
      $autocompletes             = \Drupal::entityTypeManager()->getStorage('search_api_autocomplete_search')->loadMultiple();
      $all_autocompletion_fields = [];
      foreach ($autocompletes as $autocomplete) {
        $suggester = \Drupal::service('plugin.manager.search_api_autocomplete.suggester');
        $plugin    = $suggester->createInstance('server', ['#search' => $autocomplete]);
        assert($plugin instanceof SuggesterInterface);
        $configuration         = $plugin->getConfiguration();
        $autocompletion_fields = $configuration['fields'] ?? [];
        if (!$autocompletion_fields) {
          $autocompletion_fields = $plugin
            ->getSearch()
            ->getIndex()
            ->getFulltextFields();
        }

        // Collect autocompletion fields in an array keyed by field id.
        $all_autocompletion_fields += array_flip($autocompletion_fields);
      }
    }

    // Map index fields.
    foreach ($index->getFields() as $field_id => $field_data) {
      $properties[$field_id] = MappingFactory::mappingFromField($field_data);
      // Enable fielddata for fields that are used with autocompletion.
      if (isset($all_autocompletion_fields[$field_id])) {
        $properties[$field_id]['fielddata'] = TRUE;
      }
    }

    $properties['_language'] = [
      'type' => 'keyword',
    ];

    $params['body'][$params['type']]['properties'] = $properties;

    // Allow other modules to alter index mapping before we create it.
    $dispatcher               = \Drupal::service('event_dispatcher');
    $prepareIndexMappingEvent = new PrepareIndexMappingEvent($params, $params['index'], $index);
    $event                    = $dispatcher->dispatch(PrepareIndexMappingEvent::PREPARE_INDEX_MAPPING, $prepareIndexMappingEvent);
    $params                   = $event->getIndexMappingParams();

    return $params;
  }

  /**
   * Helper function. Returns the Elasticsearch name of an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index object.
   *
   * @return string
   *   The name of the index on the Elasticsearch server. Includes a prefix for
   *   uniqueness, the database name, and index machine name.
   */
  public static function getIndexName(IndexInterface $index) {

    $options       = \Drupal::database()->getConnectionOptions();
    $site_database = $options['database'];

    return strtolower(preg_replace(
      '/[^A-Za-z0-9_]+/',
      '',
      'elasticsearch_index_' . $site_database . '_' . $index->id()
    ));
  }

  /**
   * Helper function. Returns the elasticsearch value for a given field.
   *
   * @param string $field_type
   *   Field data type.
   * @param mixed $raw
   *   Field value.
   *
   * @return mixed
   *   Field value optionally casted to specific type.
   */
  protected static function getFieldValue($field_type, $raw) {
    $value = $raw;

    switch ($field_type) {
      case 'string':
        if (!is_array($raw)) {
          $value = (string) $raw;
        }
        break;

      case 'text':
        $value = $raw->toText();
        break;

      case 'boolean':
        $value = (boolean) $raw;
        break;

      case 'integer':
        $value = (integer) $raw;
        break;

      case 'decimal':
        $value = (float) $raw;
        break;
    }

    return $value;
  }

  /**
   * Helper function. Returns true if the field is a list of values.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   Index.
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   Field.
   *
   * @return bool
   *   Returns list check result.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected static function isFieldList(IndexInterface $index, FieldInterface $field): bool {
    $is_list = FALSE;

    // Ensure we get the field definition for the
    // root/parent field item (ie tags).
    $property_definitions = $index->getPropertyDefinitions($field->getDatasourceId());
    $root_property        = Utility::splitPropertyPath($field->getPropertyPath(), FALSE)[0];
    $field_definition     = $property_definitions[$root_property];

    // Using $field_definition->isList() doesn't seem to be accurate, so we
    // check the fieldStorage cardinality !=1.
    if ($field_definition instanceof FieldConfigInterface) {
      $storage = $field_definition->getFieldStorageDefinition();
      if (1 !== $storage->getCardinality()) {
        $is_list = TRUE;
      }
    }

    // Inspect values to determine if this is an intentional array.
    $values = $field->getValues();
    if (count($values) > 1 || (!empty($values) && is_array(array_shift($values)))) {
      $is_list = TRUE;
    }

    return $is_list;
  }

}
