<?php
/**
 * FlickrImport import job
 *
 * @copyright Copyright 2014 UCSC Library Digital Initiatives
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

/**
 * The FlickrImport import job class.
 *
 * @package FlickrImport
 */
class FlickrImport_ImportJob extends Omeka_Job_AbstractJob
{
  
  /**
   * @var string Flickr API key for this plugin
   */
  public static $flickr_api_key = 'a664b4fdddb9e009f43e8a6012b1a392';
  
  /**
   * @var string URL of flickr photo or collection to import
   */
  private $url;
  
  /**
   * @var string The type of flickr collection. Allowed values
   * are "photoset", "gallery", or "photostream"
   */
  private $type;
  
  /**
   * @var string A string that uniquely identifies the photo
   * or photo collection within Flickr's database
   */
  private $setID;
  
  /**
   * @var int The collection ID of the collection to which the
   * photo or photos should be added. A value of 0 indicates 
   * that a new collection should be created.
   */
  private $collection=0;    //create new colllection by default
  
  /**
   * @var boolean Indicates whether the user has chosen specific
   * images from the collection to import
   */
  private $selecting=false;  //import all images in set by default
  
  /**
   * @var array An array containing the photoIDs of the photos
   * in the collection which the user has selected to import.
   */
  private $selected=array();
  
  /**
   * @var bool Indicates whether the newly created omeka item or 
   * items should be public.
   */
  private $public = false;  //create private omeka items by default
  
  /**
   * @var string Contains the name of the dublin core field in which
   * the flickr user's information should be stored.
   */
  private $ownerRole = "Contributor"; //flickr owner is contributor by default
  
  /**
   * @var object The flickr API interface
   */
  private $f;
  
  /**
   *Execute the import of a set of photos as a background process
   *
   *@return void
   */
  public function perform()
  {
    
    Zend_Registry::get('bootstrap')->bootstrap('Acl');

    require_once dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'phpFlickr' . DIRECTORY_SEPARATOR . 'phpFlickr.php';

    $this->f = new phpFlickr(self::$flickr_api_key);

    $this->setID = $this->_parseURL();

    if($this->collection == 0)
      $this->collection = self::MakeDuplicateCollection($this->setID,$this->type,$this->f,$this->ownerRole,$this->public);

    $photoIDs = $this->_getPhotoIDs();

    $items = array();

    echo("adding photos: <br>");
    print_r($photoIDs);
    echo("<br><br>");

    echo("selected");
    print_r($this->selected);

    $errorIDs = array();
    $errorMessage="";
    foreach ($photoIDs as $photoID)
      {
	try{
	  if(!$this->selecting || isset($this->selected[$photoID]))
	    $this->_addPhoto($photoID);
	  echo("photo added:".$photoID);
	} catch (Exception $e) {
	  $errorIDs[]=$photoID;
	  $errorMessage = $e->getMessage();
	}
      }
    if(!$errorIDs){
      $error = 'Your background import task encountered errors. ';
      $error .= count($errorIDs)." items failed to import properly. ";
      $error .= $errorMessage;
      $this->_handleError($error);
    }

  }

  /**
   *Set the Flickr url
   *
   *@param string $url URL of Flickr photo, photoset, gallery, or photostream
   *@return null
   */
  public function setUrl($url)
  {
    $this->url = $url;
  }

  /**
   *Set the collection ID
   *
   *@param string $collection The collection ID of the collection to which 
   * to add the photos
   *@return null
   */
  public function setCollection($collection)
  {
    $this->collection = $collection;
  }

  /**
   *Set the selected array
   *
   *@param array $selected An array containing the photoIDs of Flickr 
   * photos to add
   *@return null
   */
  public function setSelected($selected)
  {
    $this->selected = $selected;
  }

  /**
   *Set the selecting property
   *
   *@param boolean $selecting Indicates whether individual photos are being
   *selected in a multi-photo import
   *@return null
   */
  public function setSelecting($selecting)
  {
    $this->selecting = $selecting;
  }

  /**
   *Set the public property
   *
   *@param boolean $public Indicates whether the new omeka item or items
   * will be public
   *@return null
   */
  public function setPublic($public)
  {
    $this->public = $public;
  }

  /**
   *Set the role property
   *
   *@param string $role String containing the name of the Dublin Core element
   *to which to add the Flickr user's information
   *@return null
   */
  public function setUserRole($role)
  {
    $this->ownerRole = $role;
  }

  /**
   *Set the type property
   *
   *@param string $type The type of flickr collection. Allowed values
   * are "photoset", "gallery", or "photostream"
   *@return null
   */
  public function setType($type)
  {
    $this->type = $type;
  }

  /**
   *Parse the Flickr url parameter 
   *
   *@return $string $setID A unique identifier for the Flickr collection
   */
  private function _parseURL()
  {
    if ($this->type=='photoset')
      {
	$arr = explode('/',$this->url);
	$setID = $arr[count($arr)-2];
      }
    else if ($this->type=='gallery')
      {
	$response = $this->f->urls_lookupGallery($this->url);
	if($response['stat']=="ok")
	  $setID = $response['gallery']['id'];
	else
	  $setID =-1;
      }
    else if ($this->type=='photostream')
      {
        $response = $this->f->urls_lookupUser($this->url);
	$setID = $response['id'];
      }
    return($setID);
  }

  /**
   *Retrieve the IDs of the photos in a Flickr collection
   *
   *@return array $photoIds An array of strings corresponding to
   *the Flickr IDs of photos in a Flickr collection.
   */
  private function _getPhotoIDs()
  {
    $ids=array();

    switch($this->type)
      {
      case 'photoset':
	$list = $this->f->photosets_getPhotos($this->setID);
	break;

      case 'gallery':
	$response = $this->f->galleries_getPhotos($this->setID);
	$list['photoset']=$response['photos'];
	break;
	
      case 'photostream':
	$response = $this->f->people_getPublicPhotos($this->setID,1,"",500);	
	$total = $response['photos']['total'];
	$list['photoset']=$response['photos'];
	if($total > 500)
	  {
	    for($page=2;$total>($page-1)*500;$page++) {
	      $response = $this->f->people_getPublicPhotos($this->setID,1,"",500,$page);
	      $list['photoset']=array_merge($list['photoset'],$response['photos']);
	    }
	  }
	break;

      default:
	$list = $this->f->photosets_getPhotos($this->setID);
	if(empty($list) || ( $list['stat']=='fail' && $list['err']['code']==1 ) )
	  {
	    //photoset not found on flickr. Check if it's a gallery
	    $response = $this->f->galleries_getPhotos($this->setID);
	    $list['photoset']=$response['photos'];
	  }

	if(empty($list) || ( $list['stat']=='fail' && $list['err']['code']==1 ) )
	  {
	    $response = $this->f->urls_lookupUser($this->url);
	    $response = $this->f->people_getPublicPhotos($response['id'],1,"",500);  
	    $total = $response['photos']['total'];
	    if($total > 500)
	      echo "";
	    $list['photoset']=$response['photos'];
	  }

	break;

      }
    /*
    $list = $this->f->photosets_getPhotos($this->setID);

    if(empty($list) || ( $list['stat']=='fail' && $list['err']['code']==1 ) )
      {
	//photoset not found on flickr. Check if it's a gallery
	$response = $this->f->galleries_getPhotos($this->setID);
	$list['photoset']=$response['photos'];
      }
    */
    foreach($list['photoset']['photo'] as $photo)
      {
	$ids[]=$photo['id'];
      }

    return $ids;
  }

  /**
   *Retrieve the files associated with a given Flickr photo
   *
   *@param string $itemID The Flickr photo ID from which to extract metadata
   *@param object $f The phpFlickr interface
   *@return array $files An array of permalinks to files associated
   * with the given Flickr photo
   */
  public static function GetPhotoFiles($itemID,$f)
  {

    $sizes = $f->photos_getSizes($itemID);
    //return($sizes);
    $files = array();
    $i=0;
    $maxwidth=0;

    foreach($sizes as $key => $file)
      {
	if($file['width']>$maxwidth)
	  $i = $key;
      }

    $files[]=$sizes[$i]['source'];

    return($files);
  }


  /**
   *Fetch metadata from a Flickr photo and prepare it
   *
   *@param string $itemID The Flickr photo ID from which to extract metadata
   *@param object $f The phpFlickr interface
   *@param int $collection The ID of the collection to which to add the new item
   *@param string $ownerRole The name of the dublin core field to which to 
   *add the new omeka item or items
   *@param boolean $public Indicates whether the new omeka item should be public
   *@return array $post An array containing metadata associated with the 
   *given Flickr photo in the correct format to save as an omeka item
   */
  public static function GetPhotoPost($itemID,$f,$collection=0,$ownerRole="Contributor",$public=false)
  {
    if(empty($itemID))
      throw new Exception("Unable to retrieve photo ID from Flickr. Please check your url.");

    $response = $f->photos_getInfo($itemID);
    if(empty($response))
      $response['stat']="no response";
    if($response['stat']=="ok")
      $photoInfo = $response['photo'];
    else
      throw new Exception("Unable to retrieve photo info from Flickr. Please check your url. We asked for photo ID $itemID and flickr responded: ".$response['stat']);

    if(isset($photoInfo['media']) && $photoInfo['media']=='video')
       return('video');

    $licenses=array();
    $licenseArray = $f->photos_licenses_getInfo();
    foreach($licenseArray as $license)
      {
	$licenses[$license['id']]=$license['name'];
      }

    $datetimetaken = $photoInfo['dates']['taken'];
    $granularity = $photoInfo['dates']['takengranularity'];

    switch($granularity)
      {
      case 0:
	$date = date('Y-m-d H:i:s',strtotime($datetimetaken));
	break;
      case 4:
	$date = date('Y-m',strtotime($datetimetaken));
	break;
      case 6:
	$date = date('Y',strtotime($datetimetaken));
	break;
      case 8:
	$date = "circa ".date('Y',strtotime($datetimetaken));
	break;
	  
      }

    $maps = array(
		  "Dublin Core"=>array(
				       'Title'=>array($photoInfo['title']),
				       'Description'=>array($photoInfo['description']),
				       'Date'=>array($date),
				       'Rights'=>array($licenses[$photoInfo['license']])
				       )
		  );

    if (plugin_is_active('DublinCoreExtended'))
	$maps["Dublin Core"]["License"]=array($licenses[$photoInfo['license']]);

    if($ownerRole !== "0")
      {
        if($photoInfo['owner']['realname']!="")
	  $maps["Dublin Core"][$ownerRole] = array("Name: ".$photoInfo['owner']['realname'].'<br>Flickr username: '.$photoInfo['owner']['username']);
	else
	  $maps["Dublin Core"][$ownerRole] = array('Flickr username: '.$photoInfo['owner']['username']);
	
      }
      
    $Elements = array();

    $db = get_db();
    $elementTable = $db->getTable('Element');
    
    foreach ($maps as $elementSet => $elements)
      {

	foreach ($elements as $elementName => $elementTexts)
	  {
	    $element = $elementTable->findByElementSetNameAndElementName($elementSet,$elementName);
	    $elementID = $element->id;

	    $Elements[$elementID] = array();

	    foreach($elementTexts as $elementText)
	      {
		$text = $elementText;

		//check for html tags
		if($elementText != strip_tags($elementText)) {
		  //element text has html tags
		  $html = "1";
		}else {
		  //plain text or other non-html object
		  $html = "0";
		}

		$Elements[$elementID][] = array(
						'text' => $text,
						'html' => $html
						);
	      }
	  }
      }

    $tags = "";
    foreach($photoInfo['tags']['tag'] as $tag)
      {
	$tags .= $tag['raw'];
	$tags .=",";
      }

    $tags = substr($tags,0,-2);

    $returnArray = array(
			 'Elements'=>$Elements,
			 'item_type_id'=>'6',      //a still image
			 'tags-to-add'=>$tags,
			 'tags-to-delete'=>''
			 );

    if($collection!=-1)
      $returnArray['collection_id']=$collection;

    if($public)
      $returnArray['public']="1";

    return($returnArray );

  }


  /**
   *Create a new Omeka collection from a Flickr collection
   *
   *@param string $setID A unique identifier for the Flickr collection
   *from which to extract metadata
   *@param string $type The type of Flickr collection
   *@param object $f The phpFlickr interface
   *@param int $collection The ID of the collection to which to add the new item
   *@param string $ownerRole The name of the dublin core field to which to 
   *add the new omeka item or items
   *@param boolean $public Indicates whether the new omeka item should be public
   *@return int $id The collection ID of the newly created Omeka collection
   */
  public static function MakeDuplicateCollection($setID,$type='unknown',$f,$ownerRole=0,$public=1)
  {
    if($type=="photoset")
      {
	$setInfo = $f->photosets_getInfo($setID);
      }
    else if ($type=="gallery")
      {
	$response = $f->galleries_getInfo($setID);

	if($response['stat']=="ok")
	  $setInfo=$response['gallery'];
	else
	  throw(new Exception("Failed to retrieve gallery info from Flickr."));
      }
    else if ($type=="photostream")
      {

	$response = $f->people_getInfo($setID);
	$setInfo=array(
		       'title'=>$response['realname']." Flickr photostream",
		       'description'=>"<p>The Flickr photostream of ".$response['realname'].", who uses the Flickr username ".$response['username']."</p><p>".$response['description']."<p>",
		       'username'=>$response['username']
		       );

      }

    $maps = array(
		  "Dublin Core"=>array(
				       'Title'=>array($setInfo['title']),
				       'Description'=>array($setInfo['description'])
				       )
		  );

    if($ownerRole > "0")
      $maps["Dublin Core"][$ownerRole] = array($setInfo['username']);
      
    $Elements = array();

    $db = get_db();
    $elementTable = $db->getTable('Element');
    
    foreach ($maps as $elementSet=>$elements)
      {
	foreach($elements as $elementName => $elementTexts)
	  {
	    $element = $elementTable->findByElementSetNameAndElementName($elementSet,$elementName);
	    $elementID = $element->id;

	    $Elements[$elementID] = array();
	    foreach($elementTexts as $elementText)
	      {

		if($elementText != strip_tags($elementText)) {
		  //element text has html tags
		  $text = $elementText;
		  $html = true;
		}else {
		  //plain text or other non-html object
		  $text = $elementText;
		  $html = false;
		}

		$Elements[$elementID][] = array(
						'text' => $text,
						'html' => $html
						);
	      }
	  }
      }

    $postArray = array('Elements'=>$Elements) ;
    if($public)
      $postArray['public']="1";

    $record = new Collection();

    $record->setPostData($postArray);

    if ($record->save(false)) {
	// Succeed silently, since we're in the background
    } else {
      error_log($record->getErrors());
    }
    return($record->id);

  }

  /**
   * Create a new Omeka item with information from a Flickr image
   *
   *@param string $itemID The Flickr photo ID of the photo to be added
   * @return void
   */
  private function _addPhoto($itemID)
  {
    try{
      $post = self::GetPhotoPost($itemID,$this->f,$this->collection,$this->ownerRole,$this->public);
      
      $files = self::GetPhotoFiles($itemID,$this->f);
    } catch(Exception $e) {
      throw $e;
    }

    if(!is_array($post) && $post=='video')
      return 'video';

    $record = new Item();

    $record->setPostData($post);

    if (!$record->save(false)) {
      throw new Exception("Error saving new omeka item to database");
    }
    
    try{
      insert_files_for_item($record,'Url',$files);
    }catch(Exception $e){
      throw $e;
    }

  }

  /**
   *Send an error from the background process into Omeka for future display
   *
   *This function asynchronously calls an error logging script which 
   *saves the error message in the Omeka database, to display to the 
   *next administrator who accesses the Flickr import plugin.
   *
   *@param string $errorString The error message to send to the administrator
   *@return void
   */
  private function _handleError($errorString)
  {
    $errorUrl = url('flickr-import/index/error/error/'.$errorString);
    // create curl resource
    $ch = curl_init();
    // set url
    curl_setopt($ch, CURLOPT_URL, $errorUrl);
    curl_exec($ch);
    // close curl resource to free up system resources
    curl_close($ch);
  }


}