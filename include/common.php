<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

if (!defined('PUN_ROOT'))
	exit('The constant PUN_ROOT must be defined and point to a valid FluxBB installation root directory.');

// Define the version and database revision that this code was written for
define('FORUM_VERSION', '1.4.8');

define('FORUM_DB_REVISION', 15);
define('FORUM_SI_REVISION', 2);
define('FORUM_PARSER_REVISION', 2);

// Block prefetch requests
if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
{
	header('HTTP/1.1 403 Prefetching Forbidden');

	// Send no-cache headers
	header('Expires: Thu, 21 Jul 1977 07:30:00 GMT'); // When yours truly first set eyes on this world! :)
	header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache'); // For HTTP/1.0 compatibility

	exit;
}

// Attempt to load the configuration file config.php
$flux_config = file_exists(PUN_ROOT.'config.php') ? require PUN_ROOT.'config.php' : array();

// Load the functions script
require PUN_ROOT.'include/functions.php';

// Load UTF-8 functions
require PUN_ROOT.'modules/utf8/php-utf8.php';
require PUN_ROOT.'modules/utf8/functions/trim.php';
require_once PUN_ROOT.'modules/utf8/utils/patterns.php'; // might be already loaded by the php-utf8.php file when using mbstring extension
require_once PUN_ROOT.'modules/utf8/utils/bad.php'; // might be already loaded by the php-utf8.php file when using mbstring extension

// Strip out "bad" UTF-8 characters
forum_remove_bad_characters();

// Reverse the effect of register_globals
forum_unregister_globals();

// If PUN isn't defined, config.php is missing or corrupt
if (empty($flux_config))
{
	header('Location: install.php');
	exit;
}

// Record the start time (will be used to calculate the generation time for the page)
$pun_start = get_microtime();

// Make sure PHP reports all errors when in debug mode
if (defined('PUN_DEBUG'))
	error_reporting(E_ALL);

// Force POSIX locale (to prevent functions such as strtolower() from messing up UTF-8 strings)
setlocale(LC_CTYPE, 'C');

// Turn off magic_quotes_runtime
if (get_magic_quotes_runtime())
	set_magic_quotes_runtime(0);

// Strip slashes from GET/POST/COOKIE/REQUEST/FILES (if magic_quotes_gpc is enabled)
if (!defined('FORUM_DISABLE_STRIPSLASHES') && get_magic_quotes_gpc())
{
	function stripslashes_array($array)
	{
		return is_array($array) ? array_map('stripslashes_array', $array) : stripslashes($array);
	}

	$_GET = stripslashes_array($_GET);
	$_POST = stripslashes_array($_POST);
	$_COOKIE = stripslashes_array($_COOKIE);
	$_REQUEST = stripslashes_array($_REQUEST);
	if (is_array($_FILES))
	{
		// Don't strip valid slashes from tmp_name path on Windows
		foreach ($_FILES AS $key => $value)
			$_FILES[$key]['tmp_name'] = str_replace('\\', '\\\\', $value['tmp_name']);
		$_FILES = stripslashes_array($_FILES);
	}
}

// If a cookie name is not specified in config.php, we use the default (pun_cookie)
if (empty($cookie_name))
	$cookie_name = 'pun_cookie';

// Load the cache module
require PUN_ROOT.'modules/cache/src/cache.php';
$cache = \fluxbb\cache\Cache::load($flux_config['cache']['type'], $flux_config['cache'], $flux_config['cache']['serializer']['type'], $flux_config['cache']['serializer']);

// Define a few commonly used constants
define('PUN_UNVERIFIED', 0);
define('PUN_ADMIN', 1);
define('PUN_MOD', 2);
define('PUN_GUEST', 3);
define('PUN_MEMBER', 4);

// Load the DB module
require PUN_ROOT.'modules/database/src/Database/Adapter.php';
$db_options = array_merge($flux_config['db'], array('debug' => defined('PUN_DEBUG')));
$db = \fluxbb\database\Adapter::factory($flux_config['db']['type'], $db_options);

// Start a transaction
$db->startTransaction();

// Load cached config
$pun_config = $cache->remember('config', function() use ($db) {
	$cfg = array();

	// Get the forum config from the DB
	$query = $db->select(array('conf_name' => 'c.conf_name', 'conf_value' => 'c.conf_value'), 'config AS c');
	$params = array();

	$result = $query->run($params);
	foreach ($result as $cur_config_item)
		$cfg[$cur_config_item['conf_name']] = $cur_config_item['conf_value'];

	unset ($query, $params, $result);

	return $cfg;
});

// Verify that we are running the proper database schema revision
/*if (!isset($pun_config['o_database_revision']) || $pun_config['o_database_revision'] < FORUM_DB_REVISION ||
	!isset($pun_config['o_searchindex_revision']) || $pun_config['o_searchindex_revision'] < FORUM_SI_REVISION ||
	!isset($pun_config['o_parser_revision']) || $pun_config['o_parser_revision'] < FORUM_PARSER_REVISION ||
	version_compare($pun_config['o_cur_version'], FORUM_VERSION, '<'))
{
	header('Location: db_update.php');
	exit;
}*/

// Enable output buffering
if (!defined('PUN_DISABLE_BUFFERING'))
{
	// Should we use gzip output compression?
	if ($pun_config['o_gzip'] && extension_loaded('zlib'))
		ob_start('ob_gzhandler');
	else
		ob_start();
}

// Define standard date/time formats
$forum_time_formats = array($pun_config['o_time_format'], 'H:i:s', 'H:i', 'g:i:s a', 'g:i a');
$forum_date_formats = array($pun_config['o_date_format'], 'Y-m-d', 'Y-d-m', 'd-m-Y', 'm-d-Y', 'M j Y', 'jS M Y');

// Check/update/set cookie and fetch user info
$pun_user = array();
check_cookie($pun_user);

// Load the language system
require PUN_ROOT.'include/classes/lang.php';
Flux_Lang::setDefaultLanguage('en');
$lang = new Flux_Lang($pun_user['language']);

// Load the common language file
$lang->load('common');

// Check if we are to display a maintenance message
if ($pun_config['o_maintenance'] && $pun_user['g_id'] > PUN_ADMIN && !defined('PUN_TURN_OFF_MAINT'))
	maintenance_message();

// Load cached bans
$pun_bans = $cache->remember('bans', function() use ($db) {
	// Get the ban list from the DB
	$query = $db->select(array('id' => 'b.id', 'username' => 'b.username', 'ip' => 'b.ip', 'email' => 'b.email', 'message' => 'b.message', 'expire' => 'b.expire', 'ban_creator' => 'b.ban_creator'), 'bans AS b');
	$params = array();

	$bans = $query->run($params);
	unset ($query, $params);

	return $bans;
});

// Check if current user is banned
check_bans();

// Update online list
update_users_online();

// Check to see if we logged in without a cookie being set
if ($pun_user['is_guest'] && isset($_GET['login']))
	message($lang->t('No cookie'));

// The maximum size of a post, in bytes, since the field is now MEDIUMTEXT this allows ~16MB but lets cap at 1MB...
if (!defined('PUN_MAX_POSTSIZE'))
	define('PUN_MAX_POSTSIZE', 1048576);

if (!defined('PUN_SEARCH_MIN_WORD'))
	define('PUN_SEARCH_MIN_WORD', 3);
if (!defined('PUN_SEARCH_MAX_WORD'))
	define('PUN_SEARCH_MAX_WORD', 20);

if (!defined('FORUM_MAX_COOKIE_SIZE'))
	define('FORUM_MAX_COOKIE_SIZE', 4048);
