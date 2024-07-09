<?php

declare(strict_types=1);

namespace Drupal\s3_archive\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepository;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Recover Archived File action.
 *
 * @Action(
 *   id = "s3_archive_recover_archived_file",
 *   label = @Translation("Recover Archived File"),
 *   type = "node",
 *   category = @Translation("Custom"),
 * )
 *
 * @DCG
 * For updating entity fields consider extending FieldUpdateActionBase.
 * @see \Drupal\Core\Field\FieldUpdateActionBase
 *
 * @DCG
 * In order to set up the action through admin interface the plugin has to be
 * configurable.
 * @see https://www.drupal.org/project/drupal/issues/2815301
 * @see https://www.drupal.org/project/drupal/issues/2815297
 *
 * @DCG
 * The whole action API is subject of change.
 * @see https://www.drupal.org/project/drupal/issues/2011038
 */
final class RecoverArchivedFile extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly IslandoraUtils $utils,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepository $fileRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('islandora.utils'),
      $container->get('entity_type.manager'),
      $container->get('file.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access($entity, AccountInterface $account = NULL, $return_as_object = FALSE): AccessResultInterface|bool {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $access = $entity->access('update', $account, TRUE)
      ->andIf($entity->get('field_s3_archive_link')
        ->access('edit', $account, TRUE));
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL): void {
    $originalFileTerm = $this->utils->getTermForUri('http://pcdm.org/use#OriginalFile');
    $tid = $originalFileTerm->id();
    $s3_url = $entity->get('field_s3_archive_link')->uri;
    $name_parts = explode('-', $s3_url);
    $filename = end($name_parts);
    $image_data= file_get_contents($s3_url);
    $image = $this->fileRepository->writeData($image_data, "public://temp_image_file", FileSystemInterface::EXISTS_REPLACE);
    $image_media = Media::create([
      'name' => $entity->getTitle(),
      'bundle' => 'file',
      'langcode' => 'en',
      'status' => 1,
      'field_media_file' => [
        'target_id' => $image->id(),
        'title' => $filename,
      ],
      'field_media_use' => $tid,
      'field_media_of' => $entity->id(),
      'field_mime_type' => 'image/tif',
    ]);
    $image_media->save();
  }

}
