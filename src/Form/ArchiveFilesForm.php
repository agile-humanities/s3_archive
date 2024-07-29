<?php

declare(strict_types=1);

namespace Drupal\s3_archive\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\s3fs\S3fsFileService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a S3 archive form.
 */
final class ArchiveFilesForm extends FormBase {

  /**
   * The S3 Bucket connection.
   *
   * @var \Drupal\s3fs\S3fsFileService
   */
  protected $s3fs;

  /**
   * The Islandora Utils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Provides helpers to operate on files and stream wrappers.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 's3_archive.settings';

  /**
   * Array of terms for nodes with children.
   *
   * @var array
   */
  protected $collectionTerms;

  /**
   * Standard Constructor.
   *
   * @param \Drupal\s3fs\S3fsFileService $s3fs
   *   The S3 Filesystem.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   The Islandora Untilities.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param Psr\Log\LoggerInterface\ $logger
   *   The logger.
   */
  public function __construct(S3fsFileService $s3fs, EntityTypeManagerInterface $entity_type_manager, IslandoraUtils $utils, Connection $database, LoggerInterface $logger) {
    $this->s3fs = $s3fs;
    $this->entityTypeManager = $entity_type_manager;
    $this->utils = $utils;
    $this->database = $database;
    $this->logger = $logger;
    $this->collectionTerms = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('s3fsfileservice'),
      $container->get('entity_type.manager'),
      $container->get('islandora.utils'),
      $container->get('database'),
      $container->get('logger.channel.islandora'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 's3_archive_archive_files';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['collection'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#title' => $this->t('Collection'),
      '#description' => $this->t('Select collection.'),
      '#tags' => TRUE,
      '#selection_settings' => [
        'target_bundles' => ['islandora_object'],
      ],
      '#weight' => '0',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Archive Files'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $term_uris = [
      'http://purl.org/dc/dcmitype/Collection',
      'http://vocab.getty.edu/aat/300242735',
      'https://schema.org/Newspaper',
      'https://schema.org/Book',
      'https://schema.org/PublicationIssue',
    ];
    foreach ($term_uris as $uri) {
      $term = $this->utils->getTermForUri($uri);
      $this->collectionTerms[] = $term->id();
    }
    $collection = $form_state->getValue('collection');
    $collection_nid = $collection[0]['target_id'];
    $containers = [$collection_nid];
    $subcollections = [$collection_nid];
    while (count($subcollections) > 0) {
      $subcollections = $this->getCollectors($subcollections);
      $containers = array_merge($subcollections, $containers);
    }
    $results = $this->getResults($containers);
    $operations = [];
    $this->logger->info("Processing " . count($results));
    foreach ($results as $result) {
      $operations[] = [
        [$this, 'processResult'],
        [$result],
      ];
    }
    $batch = [
      'title' => $this->t("Archiving files..."),
      'operations' => $operations,
      'progress_message' => $this->t('Processed @current out of @total. Estimated time: @estimate.'),
      'error_message' => $this->t('The process has encountered an error.'),
    ];
    batch_set($batch);
  }

  /**
   * Gets results from database.
   *
   * @param array $collection_ids
   *   Nids of all nodes with children.
   *
   * @return array
   *   All children of collection nodes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getResults($collection_ids): array {
    $originalFileTerm = $this->utils->getTermForUri('http://pcdm.org/use#OriginalFile');
    $tid = $originalFileTerm->id();
    $sql = <<<"SQL"
    select o.field_media_of_target_id as node, m.mid as media, fm.fid, fm.uri as uri
from media m,
     media__field_media_file f,
     media__field_media_of o,
     file_managed fm,
     media__field_media_use mu
where m.mid = f.entity_id
  and m.mid = o.entity_id
  and m.mid = mu.entity_id
  and f.field_media_file_target_id = fm.fid
  and mu.field_media_use_target_id = $tid
  and o.field_media_of_target_id in (select entity_id
                                     from node__field_member_of
                                     where field_member_of_target_id in (:collection_ids[]));
SQL;
    $query = $this->database->query($sql, [':collection_ids[]' => $collection_ids]);
    return $query->fetchAll();
  }

  /**
   * Gets all sub nodes with children.
   *
   * @param array $parents
   *   The parent nodes.
   *
   * @return array
   *   The child nodes.
   */
  protected function getCollectors($parents) {
    $sql = <<<"SQL"
    select n.nid
from node n,
     node__field_member_of me,
     node__field_model mo
where n.nid = mo.entity_id
    and n.nid = me.entity_id
    and me.field_member_of_target_id in (:parents[])
    and mo.field_model_target_id in (:terms[]);
SQL;
    $query = $this->database->query($sql, [':parents[]' => $parents, ':terms[]' => $this->collectionTerms]);
    return $query->fetchCol();
  }

  /**
   * Processes each media and file.
   *
   * @param object $result
   *   Object with nid, mid, fid and uri.
   *
   * @return void
   *   Nothing is returned.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processResult($result) {
    $config = $this->config(static::SETTINGS);
    $fedora_file = fopen($result->uri, 'r');
    if (!$fedora_file) {
      return;
    }
    $temp_name = basename($result->uri);
    $temp_file = fopen("public://$temp_name", 'w');
    if ($fedora_file) {
      stream_copy_to_stream($fedora_file, $temp_file);
    }
    fclose($fedora_file);
    fclose($temp_file);
    $s3_uri = str_replace('fedora://', 's3://', $result->uri);
    $directory = dirname($s3_uri);
    $filename = basename($s3_uri);
    $this->s3fs->mkdir($directory, 509);
    $destination = "$directory/n_{$result->node}-$filename";
    $new_uri = FALSE;
    try {
      $new_uri = $this->s3fs->move("public://$temp_name", $destination);
    }
    catch (\Exception $e) {
      $this->logger->error('An error occurred: @message', ['@message' => $e->getMessage()]);
    }
    if ($new_uri) {
      $url_base = $config->get('s3_url') . '/';
      $new_uri = str_replace('s3://', $url_base, $new_uri);
      $node = $this->entityTypeManager->getStorage('node')->load($result->node);
      $node->field_s3_archive_link = $new_uri;
      $node->save();
      $deletion_candidate = $this->entityTypeManager->getStorage('media')
        ->load($result->media);
      $file = $this->entityTypeManager->getStorage('file')->load($result->fid);
      $file->delete();
      $deletion_candidate->delete();
    }
  }

}
