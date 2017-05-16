<?php
/**
 * FPFIS pre config
 */

// Set FPFIS_* , can be useful later to check if we're running of fpfis infra

define('FPFIS_CONFIG_USED', true);
define('FPFIS_ENV_RUNNING', strlen(getenv('FPFIS_ENVIRONMENT')) > 0);

if(!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT',dirname(dirname(__DIR__)));
}

if(file_exists(DRUPAL_ROOT.'/sites/default/settings.external.php')) {
    require DRUPAL_ROOT.'/sites/default/settings.external.php';
} else {
    require DRUPAL_ROOT.'/sites/default/settings.fpfis.php';
}

if(!is_array($fpfis_conf))
  die('You\'re running an FPFIS flavor of the platorm but didnt configure any $fpfis_conf, please check your config!');

/**
 * setup default ECAS settings for ecas module :
 */
if(!empty($fpfis_conf['ecas'])) {
    define('FPFIS_ECAS_URL', $fpfis_conf['ecas']['host']);
    define('FPFIS_ECAS_PORT',$fpfis_conf['ecas']['port'] );
    define('FPFIS_ECAS_URI', $fpfis_conf['ecas']['path']);
}
/**
 * Enable/fixes reverse proxy:
 */
 if(array_key_exists('reverse_proxy', $fpfis_conf)) {
   $conf['reverse_proxy'] = TRUE;
   $conf['reverse_proxy_addresses'] = array($_SERVER['REMOTE_ADDR']);
   if(!empty($_SERVER['HTTP_CLIENT_IP'])) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CLIENT_IP'];
 }

/**
 * Gelf log setup :
 */
if(array_key_exists('gelf_log',$fpfis_conf)) {
    $conf['gelf_log_group'] = $fpfis_conf['gelf_log']['group'];
    $conf['gelf_log_host'] = $fpfis_conf['gelf_log']['host'];
    $conf['gelf_log_port'] = $fpfis_conf['gelf_log']['group'];
    $conf['gelf_log_mtu'] = 1024;
}

/**
 * Proxy setup :
 */
 if (!empty($fpfis_conf['http_proxy'])) {
   $conf['proxy_server'] = $fpfis_conf['http_proxy']['host'] ;
   $conf['proxy_port'] = $fpfis_conf['http_proxy']['port'];
   $conf['proxy_user_agent'] = 'Drupal/7.x';
   $conf['proxy_exceptions'] = array('127.0.0.1', 'localhost');
   if(!empty($fpfis_conf['http_proxy']['proxy_exceptions']) && is_array($fpfis_conf['http_proxy']['proxy_exceptions']))
     $conf['http_proxy']['proxy_exceptions'] = array_merge($conf['http_proxy']['proxy_exceptions'],
         $fpfis_conf['http_proxy']['proxy_exceptions']);
   }

 if(!empty($fpfis_conf['http_proxy']['username']) && !empty($fpfis_conf['http_proxy']['password'])) {
   $conf['proxy_username'] = $fpfis_conf['http_proxy']['username'];
   $conf['proxy_password'] = $fpfis_conf['http_proxy']['password'];
 }

/**
 * URL Setup/fixes
 */
$_SERVER['HTTP_HOST'] = $fpfis_conf['host'];
$_SERVER['HTTPS'] =
    empty($_SERVER['HTTPS']) ?
        $fpfis_conf['https'] ?
            'on' : 'off'
        : $_SERVER['HTTPS'];

$_SERVER['SERVER_PORT'] = $fpfis_conf['port'];
$base_path = $fpfis_conf['path'];
$base_http =  $fpfis_conf['https'] ? 'https://' : 'http://';
$base_http .= $fpfis_conf['host'];
if(($_SERVER['HTTPS'] == 'on' && $fpfis_conf['port'] != 443) ||
    ((empty($_SERVER['https']) || strtolower($_SERVER['https'] ) == 'off') && $fpfis_conf['port'] != 80)
) {
    $base_http .= ':' . $fpfis_conf['port'];
}
$base_url = $base_http.$fpfis_conf['path'];

/**
 * Cookie setup :
*/
ini_set('session.cookie_domain', $fpfis_conf['host']);
ini_set('session.cookie_path', $fpfis_conf['path']);
