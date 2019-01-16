<?php

/**
 * @file
 * Provides the Entity source controller.
 */

class TMGMTEntitySourcePluginController extends TMGMTDefaultSourcePluginController {

  public function getLabel(TMGMTJobItem $job_item) {
    if ($entity = entity_load_single($job_item->item_type, $job_item->item_id)) {
      return entity_label($job_item->item_type, $entity);
    }
  }

  public function getUri(TMGMTJobItem $job_item) {
    if ($entity = entity_load_single($job_item->item_type, $job_item->item_id)) {
      return entity_uri($job_item->item_type, $entity);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Returns the data from the fields as a structure that can be processed by
   * the Translation Management system.
   */
  public function getData(TMGMTJobItem $job_item) {
    $entity = entity_load_single($job_item->item_type, $job_item->item_id);
    if (!$entity) {
      throw new TMGMTException(t('Unable to load entity %type with id %id', array('%type' => $job_item->item_type, $job_item->item_id)));
    }
    if (entity_language($job_item->item_type, $entity) == LANGUAGE_NONE) {
      throw new TMGMTException(t('Entity %entity could not be translated because it is language neutral', array('%entity' => entity_label($job_item->item_type, $entity))));
    }
    return tmgmt_field_get_source_data($job_item->item_type, $entity, $job_item->getJob()->source_language, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function saveTranslation(TMGMTJobItem $job_item) {
    $entity = entity_load_single($job_item->item_type, $job_item->item_id);
    $job = tmgmt_job_load($job_item->tjid);

    tmgmt_field_populate_entity($job_item->item_type, $entity, $job->target_language, $job_item->getData());

    // Change the active language of the entity to the target language.
    $handler = entity_translation_get_handler($job_item->item_type, $entity);
    $handler->setFormLanguage($job_item->getJob()->target_language);

    $entity_updated = $this->manageEmptyFieldUpdate($entity, $job_item->getData(), $job->source_language, $job->target_language);
    if ($entity_updated) {
      $entity = $entity_updated;
    }

    entity_save($job_item->item_type, $entity);

    $translation = array(
      // @todo Improve hardcoded values.
      'translate' => 0,
      'status' => TRUE,
      'language' => $job_item->getJob()->target_language,
      'source' => $job_item->getJob()->source_language,
    );
    $handler->setTranslation($translation);
    $handler->saveTranslations();
    $job_item->accepted();
  }

  /**
   * {@inheritdoc}
   */
  public function getType(TMGMTJobItem $job_item) {
    if ($entity = entity_load_single($job_item->item_type, $job_item->item_id)) {
      $bundles = tmgmt_entity_get_translatable_bundles($job_item->item_type);
      $info = entity_get_info($job_item->item_type);
      list(, , $bundle) = entity_extract_ids($job_item->item_type, $entity);
      // Display entity type and label if we have one and the bundle isn't
      // the same as the entity type.
      if (isset($bundles[$bundle]) && $bundle != $job_item->item_type) {
        return t('@type (@bundle)', array('@type' => $info['label'], '@bundle' => $bundles[$bundle]));
      }
      // Otherwise just display the entity type label.
      elseif (isset($info['label'])) {
        return $info['label'];
      }
      return parent::getType($job_item);
    }
  }


  /**
   * {@inheritdoc}
   */
  public function getSourceLangCode(TMGMTJobItem $job_item) {
    $entity = entity_load_single($job_item->item_type, $job_item->item_id);
    return isset($entity->translations->original) ? $entity->translations->original : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getExistingLangCodes(TMGMTJobItem $job_item) {
    if ($entity = entity_load_single($job_item->item_type, $job_item->item_id)) {
      $entity_info = entity_get_info($job_item->item_type);
      if (isset($entity_info['entity keys']['translations'])){
        $translations_key = $entity_info['entity keys']['translations'];
        return array_keys($entity->{$translations_key}->data);
      }
    }

    return array();
  }

  /**
   * Manage empty entity fields which are excluded from translation.
   *
   * In such case, if the field has been empty, the translation process does not take the update field,
   * however, while saving the translated entity the updated field should be taken into account.
   *
   * @param object $entity
   *   The entity.
   * @param array $data_to_translate
   *   A structured data array.
   * @param string $source_language
   *   The original language code.
   * @param string $target_language
   *   The language code to be translated to.
   *
   * @return object|null
   *   The entity wrapped data. If the entity wrapper is not altered, NULL is returned.
   */
  public function manageEmptyFieldUpdate($entity, array $data_to_translate, $source_language, $target_language) {
    // Filter the fields that are translated, and loop to the rest.
    $cache = _field_info_field_cache();
    $info = $cache->getBundleInstances($entity->entity_type, $entity->type);
    $fields = array_diff_key($info, $data_to_translate);
    $entity_wrapper = entity_metadata_wrapper($entity->entity_type, $entity->nid);

    $has_empty_field = FALSE;
    foreach ($fields as $field_name => $data) {
      if (is_array($entity->$field_name) && array_key_exists($target_language, $entity->$field_name) &&
        (!array_key_exists($source_language, $entity->$field_name) || empty($entity->$field_name[$source_language]))
      ) {
        $entity_wrapper->language($target_language)->$field_name->value->set(NULL);
        $has_empty_field = TRUE;
      }
    }

    return $has_empty_field ? $entity_wrapper->value() : NULL;
  }

}
