<?php

namespace Drupal\elasticsearch_connector\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\elasticsearch_connector\Elasticsearch\ClusterManager;
use Drupal\elasticsearch_connector\Elasticsearch\ClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a confirmation form for deletion of a custom menu.
 */
class ClusterDeleteForm extends EntityConfirmFormBase {

  /**
   * Elasticsearch Client Manager.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClientManager
   */
  private $clientManager;

  /**
   * The entity manager.
   *
   * This object members must be set to anything other than private in order for
   * \Drupal\Core\DependencyInjection\DependencySerialization to be detected.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The cluster manager service.
   *
   * @var \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager
   */
  protected $clusterManager;

  /**
   * Constructs an IndexForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClientManager $client_manager
   *   The client manager.
   * @param \Drupal\elasticsearch_connector\Elasticsearch\ClusterManager $cluster_manager
   *   The cluster manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_manager,
    ClientManager $client_manager,
    ClusterManager $cluster_manager,
    MessengerInterface $messenger
  ) {
    $this->entityManager = $entity_manager;
    $this->clientManager = $client_manager;
    $this->clusterManager = $cluster_manager;
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('elasticsearch_connector.client_manager'),
      $container->get('elasticsearch_connector.cluster_manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t(
      'Are you sure you want to delete the cluster %title?',
      ['%title' => $this->entity->label()]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t(
      'Deleting a cluster will disable all its indexes and their searches.'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = $this->entityManager->getStorage('elasticsearch_index');
    $indices = $storage->loadByProperties(
      ['server' => $this->entity->cluster_id]
    );

    // TODO: handle indices linked to the cluster being deleted.
    if (count($indices)) {
      $this->messenger->addMessage(
        $this->t(
          'The cluster %title cannot be deleted as it still has indices.',
          ['%title' => $this->entity->label()]
        ),
        'error'
      );
      return;
    }

    if ($this->entity->id() == $this->clusterManager->getDefaultCluster()) {
      $this->messenger->addMessage(
        $this->t(
          'The cluster %title cannot be deleted as it is set as the default cluster.',
          ['%title' => $this->entity->label()]
        ),
        'error'
      );
    }
    else {
      $this->entity->delete();
      $this->messenger->addMessage(
        $this->t(
          'The cluster %title has been deleted.',
          ['%title' => $this->entity->label()]
        )
      );
      $form_state->setRedirect('elasticsearch_connector.config_entity.list');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('elasticsearch_connector.config_entity.list');
  }

}
