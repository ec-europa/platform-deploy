<?php

/**
 * Implements hook_drush_command().
 */
function workbench_moderation_patch_drush_command() {

  $commands['workbench-moderation-patch-update-status'] = array(
    'description' => 'Fix content that are wrongly unpublished at "draft"
    saving time after upgrading workbench moderation from 1.4 to 3.x.
    It must run after the "update" hook of the module.',
    'callback'    => 'workbench_moderation_patch_setup_batch',
    'aliases' => array('wmpu'),
  );

  $commands['workbench-moderation-patch-update-status-simulation'] = array(
    'description' => 'Run the simulation of the of the workbench-moderation-patch-update-status (wmpu)
    command, this command will not update the database.',
    'callback'    => 'workbench_moderation_patch_simulation_batch',
    'aliases' => array('wms'),
  );

  return $commands;
}

/**
 * Define the batch operation of the update operation.
 */
function workbench_moderation_patch_setup_batch() {
  _workbench_moderation_patch_batch(FALSE);
}

/**
 * Define the batch operation of the update simulation operation.
 */
function workbench_moderation_patch_simulation_batch() {
  _workbench_moderation_patch_batch(TRUE);
}

/**
 * Helper function that defines the batch operations.
 *
 * @param bool $simulation
 *   Run the simulation or the update batch operation.
 */
function _workbench_moderation_patch_batch($simulation = TRUE) {
  $operations = [];
  // Get unpublished nodes.
  $node_query = db_select('node', 'n')
    ->fields('n', array('nid'))
    ->condition('status', 0, '=');
  $result = $node_query->execute();
  $records = $result->fetchCol();
  $total = count($records);
  $i = 0;
  if ($total > 0) {

  }

  // Prepare the batch operations.
  foreach ($records as $nid) {
    $i++;
    $operations[] = array(
      "workbench_moderation_patch_update_status",
      array($nid, $i, $total, $simulation),
    );
  }

  // Put all that information into our batch array.
  $batch = array(
    // First we update the nodes modified after the update from 1.4 to 3.0.
    'operations' => $operations,
    'title' => t('Update status'),
    // Then we fix the status of the revisions.
    'finished' => 'workbench_moderation_patch_finished',
  );

  // Get the batch process ready.
  batch_set($batch);

  // Start processing the batch operations.
  drush_backend_batch_process();
}

/**
 * Update the node if affected by "unpublished" issue.
 *
 * @param int $nid
 *   The nid of the node to treat.
 * @param int $current
 *   The current number of the iteration.
 * @param int $total
 *   The total number of nodes to update.
 */
function workbench_moderation_patch_update_status($nid, $current, $total, $simulation, &$context) {
  if (!isset($context['results']['treated_nids'])) {
    $context['results']['treated_nids'] = array();
  }
  if (!isset($context['results']['simulation'])) {
    $context['results']['simulation'] = $simulation;
    if ($simulation) {
      $initial_message = dt('Running update simulation, no changes will apply to the database.');
    }
    else {
      $initial_message = dt('Running update, the status of the some nodes will be updated in the database.');
    }
    _workbench_moderation_patch_logs($initial_message, $simulation);
  }

  // Get the nodes that were updated after updating to version 3.0 to fix
  // them in the batch process.
  // Get the last revision that was published but not current currently.
  $revision_query = db_select('workbench_moderation_node_history', 'wmnh')
    ->fields('wmnh', array('vid'))
    ->condition('state', 'published')
    ->condition('published', 0)
    ->condition('is_current', '0', '=')
    ->condition('nid', $nid)
    ->orderBy('stamp', 'DESC')
    ->range(0, 1);
  // If there is only one, it means there was a published revision,
  // which is not the current one and got unpublished by
  // workbench_moderation upgrade to its 3.x.
  if ($revision_query->countQuery()->execute()->fetchField() == 1) {
    $vid = $revision_query->execute()->fetchCol();
    if (!$context['results']['simulation']) {
      // Let's update the node to the right revision and make it published.
      db_update('node')
        ->fields(array(
          'vid' => $vid,
          'status' => 1,
        ))
        ->condition('nid', $nid)
        ->execute();
      // Also set the published flag on the right revision.
      db_update('workbench_moderation_node_history')
        ->fields(array(
          'published' => 1,
        ))
        ->condition('nid', $nid)
        ->condition('vid', $vid)
        ->execute();
      $message = dt('[!number/!total] Updated node workbench_moderation_node_history: node !nid revision !revision', array(
        '!number' => $current,
        '!total' => $total,
        '!nid' => $nid,
        '!revision' => $vid[0],
      ));
    }
    else {
      $message = dt('[!number/!total] The workbench_moderation_node_history of this node can be updated: node !nid revision !revision', array(
        '!number' => $current,
        '!total' => $total,
        '!nid' => $nid,
        '!revision' => $vid[0],
      ));
    }
    $context['results']['treated_nids'][] = $nid;
  }
  else {
    $message = dt('[!number/!total] Nothing to do on this node !nid ', array(
      '!number' => $current,
      '!total' => $total,
      '!nid' => $nid,
    ));
  }
  _workbench_moderation_patch_logs($message, $context['results']['simulation']);
}

/**
 * Update the status of the node revisions.
 *
 * Update node_revision and workbench_moderation_node_history status so they comply
 * with workbench_moderation 3.0.
 */
function workbench_moderation_patch_finished($success, $results, $operations) {
  // Get the all published nodes.
  $node_ids_query = db_select('node_revision');
  $node_ids_query->innerJoin('node', '', 'node_revision.nid = node.nid and node.status = 1');
  $node_ids_query ->fields('node_revision', array('vid'))
    ->condition('node_revision.status', '0', '=');
  $vid_ids = $node_ids_query->execute()->fetchAllAssoc('vid');
  // Get if the published node has a newer revision that is not published state.
  $vids_query = db_select('workbench_moderation_node_history', 'wmnh')
    ->fields('wmnh', array('vid'))
    ->condition('state', 'published', 'NOT LIKE')
    ->condition('is_current', '1', '=')
    ->condition('vid', array_keys($vid_ids), 'IN');
  if ($vids_query->countQuery()->execute()->fetchField()) {
    $message = '';
    // Apply the change on the subset of revisions.
    $vids = $vids_query->execute()->fetchCol();
    if (!$results['simulation']) {
      db_update('node_revision')
        ->fields(array('status' => 1))
        ->condition('vid', $vids, 'IN')
        ->execute();
      $message = dt('Update !total nodes on node_revision table (vids): !vids ', array(
        '!total' => count($vids),
        '!vids' => "[" . implode("|", $vids) . "]",
      ));
    }
    else {
      $message = dt('!total nodes on node_revision table (vids) would be updated: !vids ', array(
        '!total' => count($vids),
        '!vids' => "[" . implode("|", $vids) . "]",
      ));
    }
  }
  _workbench_moderation_patch_logs($message, $results['simulation']);
}

/**
 * Helper function to print the logs of the udpate and simulation.
 */
function _workbench_moderation_patch_logs($message, $simulation = TRUE) {
  if ($simulation) {
    if ($message != '') {
      drush_print(dt('(Running update simulation)') . $message);
    }
  }
  else {
    if ($message != '') {
      drush_log($message, 'ok');
      watchdog('workbench_moderation_patch', $message, [], WATCHDOG_INFO);
    }
  }
}
