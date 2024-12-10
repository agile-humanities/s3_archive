<?php

namespace Drupal\s3_archive\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\IslandoraUtils;
use Drupal\s3fs\S3fsFileService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 */
final class S3ArchiveCommands extends DrushCommands {
  use StringTranslationTrait;
  const SETTINGS = 's3_archive.settings';

  /**
   * Constructs a S3ArchiveCommands object.
   */
  public function __construct(
    private readonly Connection $connection,
    private readonly IslandoraUtils $utils,
    private readonly EntityTypeManager $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly S3fsFileService $s3fsFileSystem,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('islandora.utils'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('s3fsfileservice'),
    );
  }

  /**
   * Command description here.
   */
  #[CLI\Command(name: 's3_archive:s3ArchiveAll', aliases: ['s3All'])]
  #[CLI\Usage(name: 's3_archive:command-name foo', description: 'Usage description')]
  public function s3ArchiveAll() {
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
SQL;
    $query = $this->connection->query($sql);
    $results = $query->fetchAll();
    $todo = count($results);
    $this->logger()->success("$todo results to be processed");
    $operations = [];
    foreach ($results as $result) {
      $operations[] = [
        [$this, 'processResult'],
        [$result],
      ];
    }
    $batch = [
      'title' => $this->t('Migrating Original Files'),
      'operations' => $operations,
    ];
    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Processes candidate nodes.
   *
   * @param $result
   *   Candidate node.
   *
   * @return void
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processResult($result) {
    $config = $this->configFactory->get(static::SETTINGS);
    $fedora_file = fopen($result->uri, 'r');
    $temp_name = basename($result->uri);
    $temp_file = fopen("public://$temp_name", 'w');
    stream_copy_to_stream($fedora_file, $temp_file);
    fclose($fedora_file);
    fclose($temp_file);
    $s3_uri = str_replace('fedora://', 's3://', $result->uri);
    $directory = dirname($s3_uri);
    $filename = basename($s3_uri);
    $this->s3fsFileSystem->mkdir("$directory", 509, TRUE);
    $destination = "$directory/n_{$result->node}-$filename";
    $new_uri = $this->s3fsFileSystem->move("public://$temp_name", $destination);
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
