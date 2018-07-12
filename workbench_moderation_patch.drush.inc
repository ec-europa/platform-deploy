<?php

/**
 * @file
 * Drush commands analysing and fixing for the D.o issue #2958768.
 */

/**
 * Implements hook_drush_command().
 */
function workbench_moderation_patch_drush_command() {

  $commands['workbench-moderation-patch-update-status-execution'] = array(
    'description' => 'Fix content that are wrongly unpublished at "draft"
    saving time after upgrading workbench moderation from 1.4 to 3.x.
    It must run after the "update" hook of the module.',
    'callback' => 'workbench_moderation_patch_setup_batch',
    'aliases' => array('wmpusexec'),
  );

  $commands['workbench-moderation-patch-update-status-simulation'] = array(
    'description' => 'Run the simulation of the of the workbench-moderation-patch-update-status (wmpu)
    command, this command will not update the database.',
    'callback'    => 'workbench_moderation_patch_simulation_batch',
    'aliases' => array('wmpussim'),
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
  if ($simulation) {
    drush_print(dt('SIMULATION STARTED.'));
    drush_print(dt('Please note that the end results are given as guidelines.'));
    drush_print(dt('A confirmation analysis based on the simulation is recommended before executing the corrective command.'));
    drush_print('=======================================================================================================');
  }

  $operations = [];
  // Get unpublished nodes.
  $node_query = db_select('node', 'n')
    ->fields('n', array('nid'))
    ->condition('status', 0, '=');
  $result = $node_query->execute();
  $records = $result->fetchCol();
  $total = count($records);

  if (!$total) {
    $message = dt('No node is concerned by the upgrade issue. The process can stop.');
    _workbench_moderation_patch_logs($message, $simulation);
    return;
  }


  $i = 0;

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
 * @param bool $simulation
 *   Flag indicating if the command is executed a simulation mode (TRUE) or
 *   not (FALSE).
 * @param array $context
 *   The batch context.
 */
function workbench_moderation_patch_update_status($nid, $current, $total, $simulation, &$context) {
  if (!isset($context['results']['simulation'])) {
    $context['results']['simulation'] = $simulation;
    $context['results']['treated_nids'] = array();
    $initial_message = dt('Process starts, the status of the some nodes could be concerned by the problem.');
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

  // Default message for nodes that need no action.
  $message = dt(
    '[!number/!total] No correction to perform on the node !nid ', array(
      '!number' => $current,
      '!total' => $total,
      '!nid' => $nid,
    )
  );
  // If there is only one, it means there was a published revision,
  // which is not the current one and got unpublished by
  // workbench_moderation upgrade to its 3.x.
  if ($revision_query->countQuery()->execute()->fetchField() == 1) {
    $vid = $revision_query->execute()->fetchField();
    $message = dt(
      '[!number/!total] workbench_moderation_node_history table is updated: node !nid - revision !revision', array(
        '!number' => $current,
        '!total' => $total,
        '!nid' => $nid,
        '!revision' => $vid,
      )
    );

    if (!$simulation) {
      _workbench_moderation_patch_update_nodes($nid, $vid);
    }

    $context['results']['treated_nids'][] = $nid;
  }
  _workbench_moderation_patch_logs($message, $simulation);
}

/**
 * Update the status of the node revisions.
 *
 * Update node_revision and workbench_moderation_node_history status so they
 * comply with workbench_moderation 3.0.
 */
function workbench_moderation_patch_finished($success, $results, $operations) {
  // Get unpublished node revisions of published nodes.
  $node_ids_query = db_select('node_revision');
  $node_ids_query->join('node', '', 'node_revision.nid = node.nid');
  $node_ids_query->fields('node_revision', array('vid'))
    ->condition('node_revision.status', 0, '=')
    ->condition('node.status', 1, '=');

  // Get published node has a newer revision that is not published state.
  $vids_query = db_select('workbench_moderation_node_history', 'wmnh')
    ->fields('wmnh', array('vid'))
    ->condition('state', 'published', '<>')
    ->condition('is_current', '1', '=')
    ->condition('vid', $node_ids_query, 'IN');
  $vids = $vids_query->execute()->fetchCol();

  $message = dt('Update node_revision table: No revision must have their status updated.');

  if ($vids) {
    // Simulation message with the node revisions that can be updated.
    $count = count($vids);
    // Apply the change on the subset of revisions.
    if (!$results['simulation']) {
      $count = db_update('node_revision')
        ->fields(array('status' => 1))
        ->condition('vid', $vids, 'IN')
        ->execute();
    }
    $message = dt('Update node_revision table: !total revisions are affected, here are their vids: !vids ', array(
      '!total' => $count,
      '!vids' => "[" . implode(", ", $vids) . "]",
    ));
  }
  _workbench_moderation_patch_logs($message, $results['simulation']);
}

/**
 * Helper function to print the logs of the udpate and simulation.
 */
function _workbench_moderation_patch_logs($message, $simulation = TRUE) {
  if ('' == $message) {
    return;
  }
  if ($simulation) {
    drush_print(dt('SIMULATION (no writing in DB) - @message', array('@message' => $message)));
    return;
  }
  drush_log($message, 'ok');
  watchdog('workbench_moderation_patch', $message, [], WATCHDOG_INFO);
}

/**
 * Nodes to update theirs status.
 *
 * @param int $nid
 *   The nid of the node to update.
 * @param int $vid
 *   The node vid to update.
 */
function _workbench_moderation_patch_update_nodes($nid, $vid) {
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
}
