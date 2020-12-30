<?php

namespace Drupal\dst_entity_generate;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Commands\DrushCommands;

/**
 * Base class for all entity generate commands.
 */
abstract class BaseEntityGenerate extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Machine name of entity which is going to import.
   *
   * @var string
   */
  protected $entity = '';

  /**
   * Name of the entity from DST overview sheet.
   *
   * @var string
   */
  protected $dstEntityMame = '';

  /**
   * Array of all dependent modules.
   *
   * @var array
   */
  protected $dependentModules = [];

  /**
   * Validate hook for commands.
   *
   * @hook validate
   * @throws \Exception
   */
  public function validateGoogleSheetCreds() {
    $keyValueStorage = \Drupal::service('keyvalue');

    $googleSheetStorage = $keyValueStorage->get('dst_google_sheet_storage');

    $requiredConfigs = ['name', 'credentials', 'access_token', 'spreadsheet_id'];

    foreach ($requiredConfigs as $config) {
      if (empty($googleSheetStorage->get($config))) {
        throw new \Exception("Please configure $config in google sheet credentials configurations.");
      }
    }
  }

  /**
   * Helper function to display and log exception.
   *
   * @param \Exception $exception
   *   Exception object.
   * @param string $entity
   *   Entity name on which exception occurred.
   */
  public function displayAndLogException(\Exception $exception, string $entity) {
    $message = $this->t('Exception occurred while generating @entity: @exception', [
      '@exception' => $exception->getMessage(),
      '@entity' => $entity,
    ]);
    $this->yell($message);
    $this->logger->error($message);
  }

  /**
   * Validates if given entity is enabled for import or not.
   *
   * @hook pre-validate
   * @throws \Exception
   */
  public function validateEntityForImport() {
    $enabled_entities = \Drupal::configFactory()->get('dst_entity_generate.settings')->get('sync_entities');
    if ($enabled_entities[$this->dstEntityMame] !== $this->dstEntityMame) {
      throw new \Exception("Entity $this->dstEntityMame is not enabled for import. Aborting...");
    }
  }

  /**
   * Validates if given modules are enabled or not.
   *
   * @hook validate
   * @throws \Exception
   */
  public function validateModulesStatus() {
    if (empty($this->dependentModules)) {
      return;
    }

    $moduleHandler = \Drupal::moduleHandler();
    $disabledModules = [];
    foreach ($this->dependentModules as $module) {
      if (!$moduleHandler->moduleExists($module)) {
        \array_push($disabledModules, $module);
      }
    }

    if (!empty($disabledModules)) {
      $disabledModules = \implode(',', $disabledModules);
      throw new \Exception("Please enable $disabledModules to continue with this operation. Aborting...");
    }
  }

  /**
   * Get data from drupal spec tool google sheet.
   *
   * @param string $sheet
   *   Sheet tab name.
   *
   * @return array
   *   Data.
   */
  protected function getDataFromSheet(string $sheet) {
    $cache_key = 'dst_sheet_data.' . \strtolower($sheet);
    $cache_api = \Drupal::cache();

    if (!empty($cache_api->get($cache_key))) {
      $data = $cache_api->get($cache_key)->data;
    }
    else {
      $google_sheet_api = \Drupal::service('dst_entity_generate.google_sheet_api');
      $data = $google_sheet_api->getData($sheet);
      // Store cached data for 6 hours.
      $cache_api->set($cache_key, $data, microtime(TRUE) + 21600);
    }
    return $this->filterEntityTypeSpecificData($data);
  }

  /**
   * Get entity specific data from retrieved google sheet data.
   *
   * @param array $data
   *   Retrieved data.
   *
   * @return array|null
   *   Filtered data or empty.
   */
  private function filterEntityTypeSpecificData(array $data) {
    if ($this->entity === '') {
      return $data;
    }

    $filtered_data = [];
    foreach ($data as $item) {
      if ($this->converToMachineName($item['type']) === $this->entity) {
        \array_push($filtered_data, $item);
      }
    }

    return $this->filterApprovedData($filtered_data);
  }

  /**
   * Filter entity type data based on row status.
   *
   * @param array $data
   *   Data fetched from google sheet.
   *
   * @return array|null
   *   Approved data.
   */
  private function filterApprovedData(array $data) {
    if (empty($data)) {
      return;
    }

    $config = \Drupal::config('dst_entity_generate.settings');
    $column_name = $config->get('column_name');
    $column_value = $config->get('column_value');

    $approved_data = [];

    foreach ($data as $item) {
      if ($item[$column_name] === $column_value) {
        \array_push($approved_data, $item);
      }
    }
    return $approved_data;
  }

  /**
   * Convert a string to machine name.
   *
   * @param string $name
   *   Human readable name to covert into machine name.
   *
   * @return string
   *   Machine readable name.
   */
  private function converToMachineName($name) {
    return strtolower(str_replace(" ", "_", $name));
  }

}
