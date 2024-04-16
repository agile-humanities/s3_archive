<?php

declare(strict_types=1);

namespace Drupal\s3_archive\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\s3fs\S3fsFileService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a S3 archive form.
 */
final class ArchiveFilesForm extends FormBase {

  /**
   * The S# Bucket connection.
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
   * Standard Constructor.
   *
   * @param \Drupal\s3fs\S3fsFileService $s3fs
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\islandora\IslandoraUtils $utils
   * @param \Drupal\Core\Database\Connection $database
   *   *.
   */
  public function __construct(S3fsFileService $s3fs, EntityTypeManagerInterface $entity_type_manager, IslandoraUtils $utils, Connection $database) {
    $this->s3fs = $s3fs;
    $this->entityTypeManager = $entity_type_manager;
    $this->utils = $utils;
    $this->database = $database;
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
    $config = $this->config(static::SETTINGS);
    $collection = $form_state->getValue('collection');
    $collection_nid = $collection[0]['target_id'];
    $results = $this->getResults($collection_nid);
    $count = 0;
    foreach ($results as $result) {
      $fedora_file = fopen($result->uri, 'r');
      $temp_name = basename($result->uri);
      $temp_file = fopen("/tmp/$temp_name", 'w');
      stream_copy_to_stream($fedora_file, $temp_file);
      fclose($fedora_file);
      fclose($temp_file);
      $s3_uri = str_replace('fedora://', 's3://', $result->uri);
      $directory = dirname($s3_uri);
      $filename = basename($s3_uri);
      $this->s3fs->mkdir($directory, 509);
      $destination = "$directory/n_{$result->node}-$filename";
      $new_uri = $this->s3fs->move("/tmp/$temp_name", $destination);
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
      $count++;
    }
    $this->messenger()->addStatus($this->t('@count file(s) have been moved to S3', ['@count' => $count]));
  }

  /**
   * Gets results from database.
   *
   * @param $collection_id
   *
   * @return array
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getResults($collection_id): array {
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
                                     where field_member_of_target_id = $collection_id);
SQL;
    $query = $this->database->query($sql);
    return $query->fetchAll();
  }

}
