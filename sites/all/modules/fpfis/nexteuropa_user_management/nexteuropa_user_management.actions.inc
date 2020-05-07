<?php
/**
 * @file
 * Code for the NextEuropa Manage Roles feature, implemented actions.
 */

/**
 * Implements hook_action_info().
 */
function nexteuropa_user_management_action_info() {
  return array(
    'nexteuropa_user_management_block_selected_user_action' => array(
      'label' => t('Block the selected user'),
      'type' => 'user',
      'configurable' => FALSE,
      'triggers' => array(),
    ),
    'nexteuropa_user_management_unblock_selected_user_action' => array(
      'label' => t('Unblock the selected user'),
      'type' => 'user',
      'configurable' => FALSE,
      'triggers' => array(),
    ),
    'nexteuropa_user_management_change_user_roles_action' => array(
      'label' => t('Change user roles'),
      'type' => 'user',
      'configurable' => TRUE,
      'triggers' => array(),
    ),
  );
}

/**
 * Block a specific user.
 *
 * @param object $entity
 *   (optional) An entity object; if it is provided and it has a uid property,
 *   the user with that ID is blocked.
 * @param array $context
 *   (optional) An associative array; currently not used here.
 *
 * @ingroup actions
 *
 * @throws \Exception
 */
function nexteuropa_user_management_block_selected_user_action(&$entity, $context = array()) {
  // If there's no user or tries to modify himself then exit.
  if (!isset($entity->uid) || $entity->uid === $GLOBALS['user']->uid) {
    return;
  }

  $account = user_load($entity->uid);
  $account = user_save($account, array('status' => 0));
  watchdog('action', 'User %name blocked.', array('%name' => $account->name));
}

/**
 * Unblock a specific user.
 *
 * @param object $entity
 *   (optional) An entity object; if it is provided and it has a uid property,
 *   the user with that ID is unblocked.
 * @param array $context
 *   (optional) An associative array; currently not used here.
 *
 * @ingroup actions
 *
 * @throws \Exception
 */
function nexteuropa_user_management_unblock_selected_user_action(&$entity, $context = array()) {
  // If there's no user or tries to modify himself then exit.
  if (!isset($entity->uid) || $entity->uid === $GLOBALS['user']->uid) {
    return;
  }

  $account = user_load($entity->uid);
  $account = user_save($account, array('status' => 1));
  watchdog('action', 'Unblock user %name.', array('%name' => $account->name));
}

/**
 * Action settings form.
 *
 * @param array $context
 *   Provides a context for the action form.
 *
 * @return array
 *   Renderable array.
 */
function nexteuropa_user_management_change_user_roles_action_form($context) {
  if (!user_access('nexteuropa manage users non restricted operations') && !user_access('administer permissions')) {
    drupal_set_message(t('Your user does not have access to modify user roles.'), 'error');
    return array();
  }

  $roles = user_roles(TRUE);
  // Can't edit authenticated role.
  unset($roles[DRUPAL_AUTHENTICATED_RID]);

  // If the current user has access to grant any role (i.e. user 1 and
  // administrator role), it's useless to restrict here the list, however if it
  // has no this right, restrict it.
  if (!user_access('administer permissions')) {
    _nexteuropa_user_management_restrict_roles($roles);
  }

  if (empty($roles)) {
    return array(
      'message' => array(
        '#markup' => t("There's no role which you can assign."),
      ),
    );
  }

  $form = array();
  $form['add_roles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Add roles'),
    '#description' => t('Choose one or more roles you would like to assign to the selected users.'),
    '#options' => $roles,
  );
  $form['remove_roles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Remove roles'),
    '#description' => t('Choose one or more roles you would like to remove from the selected users.'),
    '#options' => $roles,
  );

  return $form;
}

/**
 * Provides a validation for the action form.
 *
 * @param array $form
 *   Action form, renderable array.
 * @param array $form_state
 *   Action form state.
 */
function nexteuropa_user_management_change_user_roles_action_validate($form, $form_state) {
  if (!$form_state['values']['add_roles'] && !$form_state['values']['remove_roles']) {
    form_set_error('add_roles', t('You have not chosen any role to add or remove. Please select something to do.'));
  }
}

/**
 * Action form submit handler, it will returns roles.
 *
 * @param array $form
 *   Action form's renderable array.
 * @param array $form_state
 *   Action form's state.
 *
 * @return array
 *   Returns the action form results. Roles to remove and add to the selected
 *   users.
 */
function nexteuropa_user_management_change_user_roles_action_submit($form, $form_state) {
  return array(
    'add_roles' => array_filter($form_state['values']['add_roles']),
    'remove_roles' => array_filter($form_state['values']['remove_roles']),
  );
}

/**
 * Add/remove roles for the 'Change user roles' action.
 *
 * @param object $entity
 *   An user entity, roles will be modified on this entity.
 * @param array $context
 *   Determinates which roles will be added, by the 'add_roles' array key, and
 *   which will be removed by the 'remove_roles' key. Changes will be made on
 *   the $entity object.
 */
function nexteuropa_user_management_change_user_roles_action($entity, $context = array()) {
  // Silently exit if:
  // - there's no user or
  // - tries to modify himself or
  // - nothing to be set or
  // - user does not have permission to change roles.
  if (
    !isset($entity->uid) ||
    $entity->uid === $GLOBALS['user']->uid ||
    empty($context['add_roles']) && empty($context['remove_roles']) ||
    !user_access('nexteuropa manage users non restricted operations') && !user_access('administer permissions')
  ) {
    return;
  }

  $wrapper = entity_metadata_wrapper('user', $entity);

  $original_roles = $roles = $wrapper->roles->value();
  if (is_array($context['add_roles'])) {
    $roles = array_merge($roles, $context['add_roles']);
  }
  if (is_array($context['remove_roles'])) {
    $roles = array_diff($roles, $context['remove_roles']);
  }
  $wrapper->roles->set($roles);
  $wrapper->save();

  watchdog('action', 'Roles was modified on %name user, added: "%added_roles", removed: "%removed_roles" and original: "%original_roles".', array(
    '%name' => $wrapper->label(),
    '%added_roles' => implode(', ', $context['add_roles']),
    '%removed_roles' => implode(', ', $context['remove_roles']),
    '%original_roles' => implode(', ', $original_roles),
  ));
}
