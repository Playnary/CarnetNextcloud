<?php
 namespace OCA\Carnet\Controller;
 use Exception;
 use OCP\App;
 use OCP\IRequest;
 use OCP\AppFramework\Controller;
 use OCP\AppFramework\Http\FileDisplayResponse;
 use OCP\AppFramework\Http\DataDisplayResponse;
 use OCP\AppFramework\Http\RedirectResponse;
 use OCP\AppFramework\Http\StreamResponse;
 use OCA\Carnet\Misc\NoteUtils;
 use OCA\Carnet\Misc\CacheManager;
 use OCP\IDBConnection;
 //require_once 'vendor/autoload.php';

 class MyZipFile extends \PhpZip\ZipFile {
     public function getInputStream(){
        return $this->inputStream;
     }
 }
 $test = "bla";
 class NoteController extends Controller {
	private $userId;
    private $bla;
    private $storage;
    private $CarnetFolder;
    private $db;
    public static $lastWrite = null;
	public function __construct($AppName, IRequest $request, $UserId, $RootFolder, $Config,  IDBConnection $IDBConnection){
        parent::__construct($AppName, $request);
        $this->userId = $UserId;
        $this->db = $IDBConnection;
        $this->Config = $Config;
        $this->rootFolder = $RootFolder;
        $folder = $this->Config->getUserValue($this->userId, $this->appName, "note_folder");
        //$this->Config->setUserValue($this->userId, $this->appName, "note_folder", 'Documents/QuickNote');
        if(empty($folder))
            $folder= NoteUtils::$defaultCarnetNotePath;
        try {
            $this->CarnetFolder = $RootFolder->getUserFolder($this->userId)->get($folder);
        } catch(\OCP\Files\NotFoundException $e) {
            $this->CarnetFolder = $RootFolder->getUserFolder($this->userId)->newFolder($folder);
        }
        if(!$this->Config->getSystemValue('has_rebuilt_cache')){
            shell_exec('php occ carnet:cache rebuild > /dev/null 2>/dev/null &');
            $this->Config->setSystemValue('has_rebuilt_cache', true);
        }
       // \OC_Util::tearDownFS();
       // \OC_Util::setupFS($UserId);
	}

	/**
	 * CAUTION: the @Stuff turns off security checks; for this page no admin is
	 *          required and no CSRF check. If you don't know what CSRF is, read
	 *          it up in the docs or you might create a security hole. This is
	 *          basically the only required method to add this exemption, don't
	 *          add it to any other method if you don't exactly know what it does
	 *
	 * @NoAdminRequired
	 */
	public function listDir() {
        $path = $_GET['path'];
        if ($path === "/" || $path === ".")
            $path = "";
        else if(substr($path, -1) !== '/' && !empty($path))
            $path .= "/";
        $paths = array();
        $data = array();
        foreach($this->CarnetFolder->get($path)->getDirectoryListing() as $in){
            $inf = $in->getFileInfo();
            $file = array();
            $file['name'] = $inf->getName();
            $file['path'] = $path.$inf->getName();
            $file['isDir'] = $inf->getType() === "dir";
            $file['mtime'] = $inf->getMtime();
            if($inf->getType() !== "dir"){
                array_push($paths, $file['path']);
            }
            array_push($data,$file);
        }
        
        $return = array();
        if(sizeof($paths)>0){
            $cache = new CacheManager($this->db, $this->CarnetFolder);
            $metadataFromCache = $cache->getFromCache($paths);
            $return['metadata'] = $metadataFromCache;
        }
        $return['files'] = $data;
        return $return;
    }

    /*
    * @NoAdminRequired
    */
    public function newFolder(){
        $path = $_POST['path'];
        $this->CarnetFolder->newFolder($path);
    }

    /**
	 * CAUTION: the @Stuff turns off security checks; for this page no admin is
	 *          required and no CSRF check. If you don't know what CSRF is, read
	 *          it up in the docs or you might create a security hole. This is
	 *          basically the only required method to add this exemption, don't
	 *          add it to any other method if you don't exactly know what it does
	 *
	 * @NoAdminRequired
	 */
	public function getRecent() {
        
        $recents = json_decode($this->getRecentFile()->getContent(),true);

        $paths = array();
        if($recents['data'] == null)
            $recents['data'] = array();
        if($recents['metadata'] != null){ //fix an old bug 
            unset($recents['metadata']);
            $this->internalSaveRecent(json_encode($recents));
        }
        foreach($recents['data'] as $item){
            $path = $item['path'];
            if(array_key_exists('newPath', $item) && $item['newPath'] != null){
                $path = $item['newPath'];
                if(in_array($item['path'], $paths)){
                    array_splice($paths, array_search($item['path'], $paths), 1);
                }

            }
            if($item['action'] == "remove"){
                if(in_array($item['path'], $paths)){
                    array_splice($paths, array_search($item['path'], $paths), 1);
                }
            } 
            else if(!in_array($path, $paths))
                array_push($paths, $path);
            
        }
        $return = array();
        if(sizeof($paths)>0){
            $cache = new CacheManager($this->db, $this->CarnetFolder);
            $metadataFromCache = $cache->getFromCache($paths);
            $return['metadata'] = $metadataFromCache;
        }
 
        $return['data'] = $recents['data'];
        return $return;
    }

    /**
    * @NoAdminRequired
    */
	public function getNotePath() {
        return substr($this->CarnetFolder->getInternalPath(),6);
    }

    /**
    * @NoAdminRequired
    */
   public function setNotePath() {
       if(!empty($_POST['path'])&& $this->rootFolder->getUserFolder($this->userId)->isValidPath($_POST['path'])){
            $this->Config->setUserValue($this->userId, $this->appName,"note_folder",$_POST['path']);
       }
       return substr($this->CarnetFolder->getInternalPath(),6);
   }

   /**
    * @NoAdminRequired
    */
    public function setUISettings($jsonSettings) {
        $this->Config->setUserValue($this->userId, $this->appName,"browser_settings",$jsonSettings);
    }

    /**
    * @NoAdminRequired
    */
    public function getUISettings() {
        $settings = $this->Config->getUserValue($this->userId, $this->appName,"browser_settings");
        if(empty($settings))
            $settings = "{}";
        return json_decode($settings);
    }

    private function getRecentFile(){
        try {
            return $this->CarnetFolder->get("quickdoc/recentdb/recentnc");
        } catch(\OCP\Files\NotFoundException $e) {
            if(!$this->CarnetFolder->nodeExists('/quickdoc/recentdb'))
                $this->CarnetFolder->newFolder('/quickdoc/recentdb', 0777, true);
            $file = $this->CarnetFolder->newFile("quickdoc/recentdb/recentnc");
            $file->putContent("{\"data\":[]}");
            return $file;
        }
    }


    /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function postKeywordsActions(){
        return $this->internalPostKeywordsActions($_POST["data"]);
     }

     private function internalPostKeywordsActions($actions){
        $recent = $this->getKeywordsDB();
        foreach($actions as $action){
            array_push($recent['data'],$action);
        }
        $this->internalSaveKeywordsDB(json_encode($recent));
        return $recent;
     }
    /**
    * @NoAdminRequired
    * @NoCSRFRequired
    */
   public function getChangelog() {
       
    $changelog = file_get_contents(__DIR__."/../../CHANGELOG.md");
    $current = App::getAppInfo($this->appName)['version'];
    $last = $this->Config->getUserValue($this->userId, $this->appName, "last_changelog_version");
    if($last !== $current){
        $this->Config->setUserValue($this->userId, $this->appName, "last_changelog_version", $current);
    }
    $result = array();
    $result['changelog'] = $changelog;
    $result['shouldDisplayChangelog'] = $last !== $current;
    return $result;
   }

   /**
    * @NoAdminRequired
    * @NoCSRFRequired
    */
    public function getKeywordsDB() {  

        return json_decode($this->getKeywordsDBFile()->getContent(),true);
    }
    
   private function getKeywordsDBFile(){
       try {
           return $this->CarnetFolder->get("quickdoc/keywords/keywordsnc");
       } catch(\OCP\Files\NotFoundException $e) {
           if(!$this->CarnetFolder->nodeExists('/quickdoc/keywords'))
                $this->CarnetFolder->newFolder('/quickdoc/keywords', 0777, true);
           $file = $this->CarnetFolder->newFile("quickdoc/keywords/keywordsnc");
           $file->putContent("{\"data\":[]}");
           return $file;
       }
   }

   /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
   public function getLangJson($lang){
     if($lang !== ".." && $lang !== "../"){
        $response = new StreamResponse(__DIR__.'/../../templates/CarnetElectron/i18n/'.$lang.".json");
        $response->addHeader("Content-Type", "application/json");
        $response->cacheFor(604800);
        return $response;
     }
     else
       die();
   }



  /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
  public function getOpusDecoderJavascript(){
    $response = new StreamResponse(__DIR__.'/../../templates/CarnetElectron/reader/libs/recorder/decoderWorker.min.js');
    $response->addHeader("Content-Type", "application/javascript");
    return $response;
  }


  /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
  public function getOpusEncoderJavascript(){
    $response = new StreamResponse(__DIR__.'/../../templates/CarnetElectron/reader/libs/recorder/encoderWorker.min.js');
    $response->addHeader("Content-Type", "application/javascript");
    return $response;
  }
  /**
	 * @PublicPage
	 * @NoCSRFRequired
     * @NoAdminRequired
   * 
*/
public function getOpusEncoder(){
    echo"bla";
    return;
  $response = new StreamResponse(__DIR__.'/../../templates/CarnetElectron/reader/libs/recorder/encoderWorker.min.wasm');
  $response->addHeader("Content-Type", "application/wasm");
  return $response;
}
  
   /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
   public function getUbuntuFont(){
      $font = basename($_SERVER['REQUEST_URI']);
      if($font !== ".." && $font !== "../")
        return new StreamResponse(__DIR__.'/../../templates/CarnetElectron/fonts/'.basename($_SERVER['REQUEST_URI']));
      else
        die();
   }

   /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function getMaterialFont(){
    $font = basename($_SERVER['REQUEST_URI']);
    if($font !== ".." && $font !== "../")
      return new StreamResponse(__DIR__.'/../../templates/CarnetElectron/fonts/'.basename($_SERVER['REQUEST_URI']));
    else
      die();
 }

   /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function mergeKeywordsDB() {
      $myDb = $this->getKeywordsDBFile();
      $hasChanged = false;
      $lastmod = $myDb->getMTime(); 
      foreach($this->CarnetFolder->get("quickdoc/keywords/")->getDirectoryListing() as $inDB){
          if($inDB->getName() === $myDb->getName()||$inDB->getMTime()<$lastmod){
              continue;
          }
          $myDbContent = json_decode($myDb->getContent());
          $thisDbContent = json_decode($inDB->getContent());
          $saveDB = false;
          foreach($thisDbContent->data as $action){
             $isIn = false;
             foreach($myDbContent->data as $actionMy){
                 if($actionMy->keyword === $action->keyword && $actionMy->time === $action->time && $actionMy->path === $action->path && $actionMy->action === $action->action){
                     $isIn = true;
                     break;
                  }         
              }
              if(!$isIn){
                 $hasChanged = true;
                 $saveDB = true;
                 array_push($myDbContent->data,$action);
              }
          }
          if($saveDB){
             usort($myDbContent->data, function($a, $b) {
                 if($a->time <= $b->time)
                    return -1;
                if($a->time >= $b->time)
                    return 1;
                return 0;
             });
             $myDb->putContent(json_encode($myDbContent));
          }
      }
      return $hasChanged;
  }

    private function getCacheFolder(){
        try {
            return $this->rootFolder->getUserFolder($this->userId)->get(".carnet/cache/".$this->userId);
        } catch(\OCP\Files\NotFoundException $e) {
            $folder = $this->rootFolder->getUserFolder($this->userId)->newFolder(".carnet/cache/".$this->userId, 0777, true);
            return $folder;
        }
    }

    /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function mergeRecentDB() {
        $lastmod = 0;
         if(!$this->CarnetFolder->nodeExists("quickdoc/recentdb/recentnc"))
            $lastmod = -1;
         $myDb = $this->getRecentFile();
         if($lastmod != -1)
            $lastmod = $myDb->getMTime(); 
         $hasChanged = false;
         foreach($this->CarnetFolder->get("quickdoc/recentdb/")->getDirectoryListing() as $inDB){
             if($inDB->getName() === $myDb->getName()||$inDB->getMTime()<$lastmod){
                 continue;
             }
             $myDbContent = json_decode($myDb->getContent());
             $thisDbContent = json_decode($inDB->getContent());
             $saveDB = false;
             foreach($thisDbContent->data as $action){
               
                $isIn = false;
                foreach($myDbContent->data as $actionMy){
                    if($actionMy->time < 10000000000)
                        $actionMy->time  = $actionMy->time  * 1000; // fix old bug
                    if($actionMy->time === $action->time && $actionMy->path === $action->path && $actionMy->action === $action->action){
                        $isIn = true;
                        break;
                     }         
                 }
                 if(!$isIn){
                    $hasChanged = true;
                    $saveDB = true;
                    array_push($myDbContent->data,$action);
                 }
             }
             if($saveDB){
                usort($myDbContent->data, function($a, $b) {
                    if($a->time <= $b->time)
                        return -1;
                    if($a->time >= $b->time)
                        return 1;
                    return 0;
                });
                $myDb->putContent(json_encode($myDbContent));
             }
         }
         return $hasChanged;
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function saveRecent($id) {
        // check if file exists and write to it if possible
        try {
            try {
                $file = $this->storage->get('/myfile.txt');
            } catch(\OCP\Files\NotFoundException $e) {
                $this->storage->touch('/myfile.txt');
                $file = $this->storage->get('/myfile.txt');
            }

            // the id can be accessed by $file->getId();
            $file->putContent($content);

        } catch(\OCP\Files\NotPermittedException $e) {
            // you have to create this exception by yourself ;)
            throw new StorageException('Cant write to file');
        }
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function internalSaveKeywordsDB($str) {
        $this->getKeywordsDBFile()->putContent($str);
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function internalSaveRecent($str) {
        $this->getRecentFile()->putContent($str);
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function postActions(){
        return $this->internalPostActions($_POST["data"]);
     }

     public function internalPostActions($actions){
        $recent = json_decode($this->getRecentFile()->getContent(),true);
        foreach($actions as $action){
            array_push($recent['data'],$action);
        }
        $this->internalSaveRecent(json_encode($recent));
        return $recent;
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function createNote(){
        $path = $_GET['path'];
        if ($path === "/" || $path === ".")
            $path = "";
        else if(substr($path, -1) !== '/' AND !empty($path))
            $path .= "/";
        $list = $this->CarnetFolder->get($path)->getDirectoryListing();
        $un = "untitled";
        $name = "";
        $i = 0;
        $continue = true;
        while($continue){
            $continue = false;
            $name = $un.(($i === 0)?"":" ".$i).".sqd";
            foreach($list as $in){
                if($in->getName() === $name){
                    $continue = true;
                    break;
                }
            }
            $i++;
        }
        return $path.$name;
        
     }

      /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
	 public function updateMetadata($path, $metadata){
    
        $tmppath = tempnam(sys_get_temp_dir(), uniqid().".zip");
        $tmppath2 = tempnam(sys_get_temp_dir(), uniqid().".zip");
        if(!file_put_contents($tmppath, $this->CarnetFolder->get($path)->fopen("r")))
            return;

        $zipFile = new \PhpZip\ZipFile();
        $zipFile->openFromStream(fopen($tmppath, "r")); //issue with encryption when open directly + unexpectedly faster to copy before Oo'
        $zipFile->addFromString("metadata.json", $metadata, \PhpZip\ZipFile::METHOD_DEFLATED);
        $zipFile->saveAsFile($tmppath2);
        $tmph = fopen($tmppath2, "r");
        if($tmph){
            try{
                $file = $this->CarnetFolder->get($path);
                $file->putContent($tmph);   
            } catch(\OCP\Files\NotFoundException $e) {
            }
            fclose($tmph);
        } else 
            throw new Exception('Unable to create Zip');
       unlink($tmppath);	
       unlink($tmppath2);	

     }


     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
	 public function getMedia($note, $media){
        $tmppath = tempnam(sys_get_temp_dir(), uniqid().".zip");
        $node = $this->CarnetFolder->get($note);
        $response = null;
        file_put_contents($tmppath, $node->fopen("r"));
        try{
            $zipFile = new \PhpZip\ZipFile();
            $zipFile->openFromStream(fopen($tmppath, "r")); //issue with encryption when open directly + unexpectedly faster to copy before Oo'
            $response = new DataDisplayResponse($zipFile->getEntryContents($media));
            $response->addHeader("Content-Type", "image/jpeg");

        } catch(\PhpZip\Exception\ZipNotFoundEntry $e){
            $response = $media;
            
        }
        unlink($tmppath);
        return $response;
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
	 public function getMetadata($paths){
		$array = array();
        $pathsAr = explode(",",$paths);
        $cache = new CacheManager($this->db, $this->CarnetFolder);
        $metadataFromCache = $cache->getFromCache($pathsAr);
		foreach($pathsAr as $path){
			if(empty($path))
                continue;
            try{
                
                if(!array_key_exists($this->CarnetFolder->getFullPath($path), $metadataFromCache)){
                    $utils = new NoteUtils();
                    try{
                        $meta = $utils->getMetadata($this->CarnetFolder, $path);
                        $array[$path] = $meta;
                        $cache->addToCache($path, $meta, $meta['lastmodfile']);
                    } catch(\PhpZip\Exception\ZipException $e){

                    }
                }
			} catch(\OCP\Files\NotFoundException $e) {
            } catch(\OCP\Encryption\Exceptions\GenericEncryptionException $e){
            }
           
        }
        $array = array_merge($metadataFromCache, $array);
		return $array;
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
	 public function search($from, $query){
        try {
            $this->getCacheFolder()->get("carnet_search")->delete();
        } catch(\OCP\Files\NotFoundException $e) {
            
        }
        shell_exec('php occ carnet:search '.escapeshellarg($this->userId).' '.escapeshellarg($query).' '.escapeshellarg($from).'> /dev/null 2>/dev/null &');
    }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getSearchCache(){
        $return = array();
        $return["files"] = array();

        try {
            $c = json_decode($this->getCacheFolder()->get("carnet_search")->getContent());
            if($c){
                $return["files"] = $c;
            }
               
        } catch(\OCP\Files\NotFoundException $e) {  
        } catch(\OCP\Lock\LockedException $e){
            sleep(2);
            $c = json_decode($this->getCacheFolder()->get("carnet_search")->getContent());
            if($c)
                $return["files"] = $c;
        }
        return $return;
    }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
	 public function deleteNote($path){
		$this->CarnetFolder->get($path)->delete();
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
    public function moveNote($from, $to){     
        if($this->CarnetFolder->nodeExists($to)){
            throw new Exception("Already exists");
        }
       $this->CarnetFolder->get($from)->move($this->CarnetFolder->getFullPath($to));
       $actions = array();
       $actions[0] = array();
       $actions[0]['action'] = "move";
       $actions[0]['path'] = $from;
       $actions[0]['newPath'] = $to;
       $actions[0]['time'] = time()*1000;
       $this->internalPostActions($actions);
       $this->internalPostKeywordsActions($actions);
    }

      /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */

     public function getEditorUrl(){
         return "./writer";
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function saveTextToOpenNote(){
        $id = $_POST['id'];
        $this->waitEndOfExtraction($id);
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        try{
            $file = $folder->get("index.html");
        } catch(\OCP\Files\NotFoundException $e) {
            $file = $folder->newFile("index.html");
        }
        $file->putContent($_POST['html']);
        try{
            $file = $folder->get("metadata.json");
        } catch(\OCP\Files\NotFoundException $e) {
            $file = $folder->newFile("metadata.json");
        }
        $file->putContent($_POST['metadata']);

        $this->saveOpenNote($_POST['path'],$id);
     }


     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function deleteMediaFromOpenNote($id){
        $this->waitEndOfExtraction($id);
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        
        $folder->get("data/".$_GET['media'])->delete();
        try{
            $folder->get("data/preview_".$_GET['media'].".jpg")->delete();
        } catch(\OCP\Files\NotFoundException $e) {
        }
        $this->saveOpenNote($_GET['path'],$id);
        return $this->listMediaOfOpenNote($id);

    }
     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function addMediaToOpenNote($id){
        $this->waitEndOfExtraction($id);
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        
        try{
            $data = $folder->get("data");
        } catch(\OCP\Files\NotFoundException $e) {
            $data = $folder->newFolder("data");
        }
        $fileIn = fopen($_FILES['media']['tmp_name'][0],"r");
        if (!$fileIn) {
            throw new Exception('Media doesn\'t exist');
        } else {
            $fileOut = $data->newFile($_FILES['media']['name'][0]);
            $fileOut->putContent($fileIn);
            if(@is_array(getimagesize($_FILES['media']['tmp_name'][0]))){
                $fn = $_FILES['media']['tmp_name'][0];
                $size = getimagesize($fn);
                $ratio = $size[0]/$size[1]; // width/height
                if( $ratio > 1) {
                    $width = 400;
                    $height = 400/$ratio;
                }
                else {
                    $width = 400*$ratio;
                    $height = 400;
                }
                $src = imagecreatefromstring(file_get_contents($fn));
                $dst = imagecreatetruecolor($width,$height);
                imagecopyresampled($dst,$src,0,0,0,0,$width,$height,$size[0],$size[1]);
                imagedestroy($src);
                $fileOut = $data->newFile("preview_".$_FILES['media']['name'][0].".jpg");
                imagejpeg($dst,$fileOut->fopen("w"));
                imagedestroy($dst);
            }
            fclose($fileIn);
        }
        $this->saveOpenNote($_POST['path'],$id);
        return $this->listMediaOfOpenNote($id);
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function listMediaOfOpenNote($id){
        $this->waitEndOfExtraction($id);
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        $media = array();
        try{
            $data = $folder->get("data");
            foreach($data->getDirectoryListing() as $in){
                if(substr($in->getName(), 0, strlen("preview_")) !== "preview_")
                    array_push($media,"note/open/".$id."/getMedia/".$in->getName());
            }
        } catch(\OCP\Files\NotFoundException $e) {
            
        }
        return $media;
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function getMediaOfOpenNote($id, $media){
        $this->waitEndOfExtraction($id);
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        $data = $folder->get("data");
        $f = $data->get($media);
        $r = new DataDisplayResponse($f->getContent());

        $r->addHeader("Content-Disposition", "attachment");
        $r->addHeader("Content-Type", $f->getMimeType());

        return $r;
     }

     private function saveOpenNote($path,$id){
        $cache = $this->getCacheFolder();
        $folder = $cache->get("currentnote".$id);
        $zipFile = new MyZipFile();
        $meta = array();
        $meta["previews"] = array();
        $previews = $this->addFolderContentToArchive($folder,$zipFile,"");
        foreach($previews as $preview){
            array_push($meta['previews'], "./note/getmedia?note=".$path."&media=".$preview);
        }
        $file = $this->CarnetFolder->newFile($path);
        //tried to do with a direct fopen on $file but lead to bad size on nextcloud
        $tmppath = tempnam(sys_get_temp_dir(), uniqid().".sqd");
        $zipFile->saveAsFile($tmppath);
        $tmph = fopen($tmppath, "r");
        if($tmph){
            try{
                $this->CarnetFolder->get($path)->delete();
                $file = $this->CarnetFolder->newFile($path);

            } catch(\OCP\Files\NotFoundException $e) {
            }
            
            $file->putContent($tmph);
            fclose($tmph);
            $meta['metadata'] = json_decode($folder->get("metadata.json")->getContent());
            $meta['shorttext'] = NoteUtils::getShortTextFromHTML($folder->get("index.html")->getContent());
            $cache = new CacheManager($this->db, $this->CarnetFolder);
            $cache->addToCache($path, $meta, $file->getFileInfo()->getMtime());

        } else 
            throw new Exception('Unable to create Zip');

        unlink($tmppath);
     } 
     /*
        returns previews
     */
     private function addFolderContentToArchive($folder, $archive, $relativePath){
         $previews = array();
        foreach($folder->getDirectoryListing() as $in){
            $inf = $in->getFileInfo();
            $path = $relativePath.$inf->getName();
            if($inf->getType() === "dir"){
                $archive->addEmptyDir($path);
                $previews = array_merge($previews, $this->addFolderContentToArchive($in, $archive, $path."/"));
            }else {
                $archive->addFromStream($in->fopen("r"), $path, \PhpZip\ZipFile::METHOD_DEFLATED);
                if(substr($path,0,strlen("data/preview_")) === "data/preview_"){
                    array_push($previews, $path);
                }
            }

        }
        return $previews;
     }

     private function getCurrentnoteDir(){
        $cache = $this->getCacheFolder();
        //find current note folder
        foreach($cache->getDirectoryListing() as $in){
             if(substr($in->getName(), 0, strlen("currentnote")) === "currentnote"){
                 return $in;
             }
        }
        return null;
     }

     private function waitEndOfExtraction($id){
        $cache = $this->getCacheFolder();
        do{
         if($cache->nodeExists("currentnote".$id."/.extraction_finished"))
            return;
            sleep(1);
        }while(true); 
    }
    
     
     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function openNote(){
        $editUniqueID = uniqid();
        $data = array();

        $path = $_GET['path'];
        $cache = $this->getCacheFolder();
        try{
            $tmppath = tempnam(sys_get_temp_dir(), uniqid().".zip");
            file_put_contents($tmppath,$this->CarnetFolder->get($path)->fopen("r"));
            $zipFile = new MyZipFile();
            $zipFile->openFile($tmppath);
            try{
                $data['metadata'] = json_decode($zipFile['metadata.json']);
            } catch(\PhpZip\Exception\ZipNotFoundEntry $e){}
            $data['html'] = $zipFile['index.html'];
            unlink($tmppath);
        } catch(\OCP\Files\NotFoundException $e) {
            $data["error"] = "not found";
        }

        $data['id'] = $editUniqueID;
        return $data; 

     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function extractNote(){
        $path = $_GET['path'];
        $editUniqueID = $_GET['id'];
        $cache = $this->getCacheFolder();
        /*
            because of an issue with nextcloud, working with a single currentnote folder is impossible...
        */    
        foreach($cache->getDirectoryListing() as $in){
            if(substr($in->getName(), 0, strlen("currentnote")) === "currentnote"){
                $in->delete();
            }
        }
        $folder = $cache->newFolder("currentnote".$editUniqueID);
        try{
            $tmppath = tempnam(sys_get_temp_dir(), uniqid().".zip");
            file_put_contents($tmppath,$this->CarnetFolder->get($path)->fopen("r"));
            $zipFile = new \PhpZip\ZipFile();
            $zipFile->openFile($tmppath);
            foreach($zipFile as $entryName => $contents){
            if($entryName === ".extraction_finished")
            continue;    
            if($contents === "" AND $zipFile->isDirectory($entryName)){
                $folder->newFolder($entryName);
            }
            else if($contents !== "" && $contents !== NULL){
                $parent = dirname($entryName);
                if($parent !== "." && !$folder->nodeExists($parent)){
                    $folder->newFolder($parent);
                }
                $folder->newFile($entryName)->putContent($contents);
            }
        }
        unlink($tmppath);
        } catch(\OCP\Files\NotFoundException $e) {
        }
        $folder->newFile(".extraction_finished");
    }

     /**
      * @NoAdminRequired
      *
      * @param string $title
      * @param string $content
      */
     public function create($title, $content) {
         // empty for now
     }

     /**
      * @NoAdminRequired
      *
      * @param int $id
      * @param string $title
      * @param string $content
      */
     public function update($id, $title, $content) {
         // empty for now
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function isFirstRun(){
        $isFirst = $this->Config->getUserValue($this->userId, $this->appName, "is_first_run",1);
        $this->Config->setUserValue($this->userId, $this->appName, "is_first_run", 0);
        return $isFirst;
     }
     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function downloadArchive(){

        return new RedirectResponse("../../../../../index.php/apps/files/ajax/download.php?files=".$this->getNotePath());
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function importNote(){
    
     }


     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getAppThemes(){
        $root = $this->getCarnetElectronUrl()."/css/";
        return json_decode('[{"name":"Carnet", "path":"'.$this->getCarnetElectronPath().'/css/carnet", "preview":"'.$root.'carnet/preview.png"}, {"name":"Dark", "path":"'.$this->getCarnetElectronPath().'/css/dark", "preview":"'.$root.'dark/preview.png"}]');
    }

    private function getCarnetElectronPath(){
        return __DIR__.'/../../templates/CarnetElectron';
    }

    private function getCarnetElectronUrl(){
        $root = \OCP\Util::linkToAbsolute($this->appName,"templates")."/CarnetElectron";
        if(strpos($root,"http://") === 0 && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off'){
            //should be https...
            $root = "https".substr($root,strlen("http"));
        }
        return $root;
    }
     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function setAppTheme($url){
         if(strpos($url, '/') !== 0 && strpos($url, 'http') !==0)
            throw new Exception("forbidden");
        
        $meta = json_decode(file_get_contents($url."/metadata.json"),true);
        $browser = array();
        $editor = array();
        $settings = array();
        if(strpos($url, $this->getCarnetElectronPath()) === 0){
            $url = $this->getCarnetElectronUrl().substr($url, strlen($this->getCarnetElectronPath()), strlen($url));
        }
        foreach($meta['browser'] as $css){
            array_push($browser, $url."/".$css);
        }
        $this->Config->setUserValue($this->userId, $this->appName,"css_browser", json_encode($browser));
        foreach($meta['editor'] as $css){
            array_push($editor, $url."/".$css);
        }
        $this->Config->setUserValue($this->userId, $this->appName,"css_editor", json_encode($editor));
        foreach($meta['settings'] as $css){
            array_push($settings, $url."/".$css);
        }
        $this->Config->setUserValue($this->userId, $this->appName,"css_settings", json_encode($settings));
        
     }

     /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
      public function getEditorCss(){
        $css = $this->Config->getUserValue($this->userId, $this->appName, "css_editor");
        if(empty($css))
          $css = "[]";
        return json_decode($css);
      }

      /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getBrowserCss(){
        $css = $this->Config->getUserValue($this->userId, $this->appName, "css_browser");
        if(empty($css))
          $css = "[]";
        return json_decode($css);
    }


      /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getSettingsCss(){
        $css = $this->Config->getUserValue($this->userId, $this->appName, "css_settings");
        if(empty($css))
          $css = "[]";
        return json_decode($css);
    }


 }
?>
