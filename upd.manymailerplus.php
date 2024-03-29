<?php  if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}
require_once(PATH_THIRD . '/manymailerplus/config.php');
use ExpressionEngine\Service\Addon\Installer; //required
class Manymailerplus_upd extends Installer
{
    public $version = EXT_VERSION;
    public $has_cp_backend = 'n';
    public $has_publish_fields = 'n';

    // function __construct(){
    // 	if (!ee()->load->is_loaded('dbg')) ee()->load->library('debughelper', array('debug'=>true), 'dbg');
    // }
    public function ee_version()
    {
        return substr(APP_VER, 0, 1);
    }

    public $methods = [
        [
            'hook' => 'email_send',
            'priority' => 1,
            'enabled' => 'y'
        ]
    ];

    public function __construct()
    {
        parent::__construct();
    }
    public function install()
    {
        $this->settings = array();
        


        // ADD EXTENSION FOR SERVICES INTEGRATION
        $ext_data = array(
            'class'     => ucfirst(EXT_SHORT_NAME).'_ext',
            'method'    => 'email_send',
            'hook'      => 'email_send',
            'settings'  => serialize($this->settings),
            'version'   => $this->version,
            'priority'  => 1,
            'enabled'   => 'y'
        );
        ee()->db->insert('extensions', $ext_data);

        $mod_data = array(
            'module_name' => EXT_NAME,
            'module_version' => $this->version,
            'has_cp_backend' => 'y',
            'has_publish_fields' => 'n'
        );

        $previousInstall = ee()->db->get_where('modules', $mod_data);
        if ($previousInstall->num_rows() == 0) {
            ee()->db->insert('modules', $mod_data);
        }
        $this->createCache();
        return parent::install();
    }
    

    public function uninstall()
    {
        // ADD EXTENSION FOR SERVICES INTEGRATION
        ee()->db->where('class', ucfirst(EXT_SHORT_NAME).'_ext');
        ee()->db->delete('extensions');

        ee()->db->where('module_name', EXT_NAME);
        ee()->db->delete('modules');

        ee()->db->delete('modules', array( 'module_name' => EXT_NAME));

        ee()->load->dbforge();
        $sql[] = "DROP TABLE IF EXISTS exp_email_cache_plus";
        $sql[] = "DROP TABLE IF EXISTS exp_email_queue_plus";
        $this->runSQL($sql);
        return parent::uninstall();
    }


    public function update($version = '')
    {
        // if (version_compare($version, '0.0.0', '>=')) {
           
        // }
        if (version_compare($version, '4.0.0', '<=')) {            
            return $this->createCache();
        } 
       
    }

    public function createCache()
    {
        ee()->load->dbforge();

        $sql[] = "DROP TABLE IF EXISTS `exp_email_cache_plus`";
        $sql[] = "DROP TABLE IF EXISTS `exp_email_queue_plus`";
        $sql[] = "CREATE TABLE IF NOT EXISTS `exp_email_queue_plus`(
            `queue_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
            `queue_start` int(10) unsigned NOT NULL DEFAULT '0',            
            `queue_end` int(10) unsigned NOT NULL DEFAULT '0',
            `email_id` int(6) unsigned NOT NULL,
            `recipient_count` int(6) unsigned NOT NULL DEFAULT '0',
            `sent` int(6) unsigned NOT NULL  DEFAULT '0',
            `messages` text COLLATE utf8mb4_unicode_ci NULL,
            `active` tinyint(1) unsigned DEFAULT '1',
            PRIMARY KEY (`queue_id`)
			) ENGINE=InnoDB AUTO_INCREMENT=2570 DEFAULT CHARACTER SET ".ee()->db->escape_str(ee()->db->char_set)." COLLATE ".ee()->db->escape_str(ee()->db->dbcollat);

        $sql[] = "CREATE TABLE IF NOT EXISTS `exp_email_cache_plus`(
			`cache_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
            `parent_id` int(6) unsigned NULL,
  			`cache_date` int(10) unsigned NOT NULL DEFAULT '0',
			`total_sent` int(6) unsigned NOT NULL,
			`from_name` varchar(70) COLLATE utf8mb4_unicode_ci NOT NULL,
			`from_email` varchar(75) COLLATE utf8mb4_unicode_ci NOT NULL,
			`recipient` text COLLATE utf8mb4_unicode_ci NOT NULL,
			`cc` text COLLATE utf8mb4_unicode_ci NOT NULL,
			`bcc` text COLLATE utf8mb4_unicode_ci NOT NULL,
			`recipient_array` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
			`subject` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
			`message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
			`plaintext_alt` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
			`mailtype` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
			`text_fmt` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
			`wordwrap` char(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'y',
			`attachments` mediumtext COLLATE utf8mb4_unicode_ci,
			`csv_object` json DEFAULT NULL,
			`mailKey` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			PRIMARY KEY (`cache_id`)
			) ENGINE=InnoDB AUTO_INCREMENT=2570 DEFAULT CHARACTER SET ".ee()->db->escape_str(ee()->db->char_set)." COLLATE ".ee()->db->escape_str(ee()->db->dbcollat);
        $this->runSQL($sql);
        return true;
    }

    private function runSQL($sql = array())
    {
        foreach ($sql as $query) {
            ee()->db->query($query);
        }
    }
}
