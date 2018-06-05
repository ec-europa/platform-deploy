<?php

/**
 * @file
 * Drush commands for the module.
 */

/**
 * Implements hook_drush_command().
 */
function smoketest_drush_command() {
  $items = [];

  $items['smoketest-watchdog'] = [
    'description' => 'Crawl site and report watchdog errors and notifications back to user.',
    'callback'    => 'smoketest_smoketest_watchdog',
    'drupal dependencies' => ['dblog'],
    'aliases' => ['wd-smoke', 'wdsm'],
  ];

  return $items;
}

/**
 * Implements hook_drush_help().
 */
function smoketest_drush_help($section) {
  switch ($section) {
    case 'drush:smoketest-watchdog':
      return dt("Crawl site and report watchdog errors and notifications back to user.");
      break;
  }
  return '';
}

/**
 * Revert all enabled features to their definitions in code.
 */
function smoketest_smoketest_watchdog() {
  // Get cookie for root user.
  global $user;
  $user = user_load(1);
  $session_id = session_id();
  // Did not find other way to login through cli
  // $uli = user_pass_reset_url($user);
  // $request = drupal_http_request($uli);
  _drupal_session_write($session_id, '');
  $options = [
    'headers' => [
      'Cookie' => session_name() . '=' . $session_id,
    ],
  ];

  // Always add frontpage.
  $paths = [
    '/admin/config',
  ];

  // Gather secondary urls.
  $paths = generateUrlsByContentTypes($paths);
  $paths = generateUrlsByTaxonomies($paths);
  $paths = generateUrlsByViews($paths);
  $paths = generateUrlsByPageManager($paths);
  if (count($paths) >= 1) {
    foreach($paths as $path) {
      $url = url($path,  array('absolute' => TRUE));
      $visit = drupal_http_request($url, $options);
    }
  }
  _drupal_session_destroy($session_id);
}

/**
 * Generate the list of URL's based in the Content-types configuration.
 *
 * @return array
 *   List of URL's to test.
 */
function generateUrlsByContentTypes($paths)
{
  $node_types = db_select('node_type', 'nt')
    ->fields('nt', ['type', 'name'])
    ->condition('nt.disabled', '0', '=')
    ->execute()
    ->fetchAll();
  if (!empty($node_types)) {
    foreach ($node_types as $node_type) {
      $types[] = $node_type->type;
    }
  }
  // Look for content in database.
  $nodes = db_select('node', 'n')
    ->fields('n', array('nid', 'type'))
    ->condition('n.type', $types, 'IN')
    ->groupBy('n.type')
    ->condition('status', 0, '>')
    ->execute()
    ->fetchAll();
  if (!empty($nodes)) {
    foreach ($nodes as $node) {
      $path = 'node/' . $node->nid;
      if (drupal_valid_path($path)) {
        $paths[] = url($path);
      }
    }
  }
  return $paths;
}
/**
 * Generate the list of URL's based in the Taxonomy configuration.
 *
 * @return array
 *   List of URL's to test.
 */
function generateUrlsByTaxonomies($paths)
{
  if (module_exists('taxonomy')) {
    $taxonomies = db_select('taxonomy_term_data', 'ttd')
      ->fields('ttd', array('tid'))
      ->groupBy('ttd.vid')
      ->execute()
      ->fetchAll();
    if (!empty($taxonomies)) {
      foreach ($taxonomies as $taxonomy) {
        $path = 'taxonomy/term/' . $taxonomy->tid;
        if (drupal_valid_path($path)) {
          $paths[] = url($path);
        }
      }
    }
  }
  return $paths;
}
/**
 * Generate the list of URL's based in the Search module.
 *
 * @return array
 *   List of URL's to test.
 */
function generateUrlsBySearch($paths)
{
  if (module_exists('search') && drupal_valid_path('search')) {
    $paths[] = url('search');
  }
  return $paths;
}
/**
 * Generate the list of URL's based in the Views configuration.
 *
 * @return array
 *   List of URL's to test.
 */
function generateUrlsByViews($paths)
{
  if (module_exists('views')) {

    $all_views = views_get_all_views();
    foreach ($all_views as $view) {

      // Load all views displays to check for missing handlers.
      if (module_exists('devel')) {

        $views_debug = variable_get('views_devel_output', FALSE);
        $views_region = variable_get('views_devel_region', 'watchdog');
        variable_set('views_devel_output', TRUE);
        variable_set('views_devel_region', 'message');

        $displays = array_keys($view->display);
        foreach($displays as $display) {
           $view->execute($display);
           $view = $view->clone_view();
        }

        variable_set('views_devel_output', $views_debug);
        variable_set('views_devel_region', $views_region);
      }

      // Get the page views to visit later for detecting other watchdog entries.
      if (empty($view->disabled)) {
        foreach ($view->display as $display) {
          if ($display->display_plugin == 'page') {
            if (drupal_valid_path($display->display_options['path'])) {
              $paths[] = url($display->display_options['path']);
            }
          }
        }
      }
    }
  }
  return $paths;
}
/**
 * Generate the list of URL's based in the Page Manager configuration.
 *
 * @return array
 *   List of URL's to test.
 */
function generateUrlsByPageManager($paths)
{
  if (module_exists('page_manager')) {
    $pages = db_select('page_manager_pages', 'pmp')
      ->fields('pmp', array('path'))
      ->execute()
      ->fetchAll();
    if (!empty($pages)) {
      foreach ($pages as $page) {
        $path = $page->path;
        if (drupal_valid_path($path)) {
          $paths[] = url($path);
        }
      }
    }
  }
  return $paths;
}