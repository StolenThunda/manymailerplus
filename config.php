<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
	// EXT_NAME - Extension name
	$extensionName = "ManyMailerPlus";
	if( ! defined('EXT_VERSION') )
	{
		define('EXT_VERSION', '1.0.1');
		define('EXT_NAME', $extensionName);
		define('EXT_SHORT_NAME', strtolower($extensionName));
		define('EXT_SETTINGS_PATH', 'addons/settings/'.strtolower($extensionName));
	}
