<?php
/**
 * Flickr plugin
 *
 * @copyright Copyright 2014 UCSC Library Digital Initiatives
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

//require_once dirname(__FILE__) . '/helpers/SedMetaFunctions.php';

/**
 * FlickrImport plugin.
 */
class FlickrImportPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array Hooks for the plugin.
     */
    //protected $_hooks = array('admin_head');
  protected $_hooks = array('initialize','define_acl','admin_head');

  /**
   * @var array Filters for the plugin.
   */
  protected $_filters = array('admin_navigation_main');

  /**
   * @var array Options and their default values.
   */
  protected $_options = array();

  public function hookInitialize()
  {
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'import.php';
  }


  public function hookAdminHead()
  {
    queue_js_file('FlickrImport');
    queue_css_file('FlickrImport');
  }



  /**
   * Define the plugin's access control list.
   */
  public function hookDefineAcl($args)
  {
    $args['acl']->addResource('FlickrImport_Index');
  }

   
  /**
   * Add the SedMeta link to the admin main navigation.
   * 
   * @param array Navigation array.
   * @return array Filtered navigation array.
   */
  public function filterAdminNavigationMain($nav)
  {
    $nav[] = array(
		   'label' => __('Flickr Import'),
		   'uri' => url('flickr-import'),
		   'resource' => 'FlickrImport_Index',
		   'privilege' => 'index'
		   );
    return $nav;
  }

    private function _getUrl($urlstring)
    {
      $self = $_SERVER['PHP_SELF'];
      return $self.$urlstring;
    }
    
}
