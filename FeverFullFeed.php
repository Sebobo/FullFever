<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Daniel Lienert <daniel@lienert.cc>, Daniel Lienert
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

class FeverFullFeed {

	/**
	* Configuration parameters
	*/

	// MySQL
	protected $mysqlHost = '';
	protected $mysqlUser = '';
	protected $mysqlPassword = '';
	protected $mysqlFeverDb = '';


	protected $feedConfiguration = array();

	/**
	* @var PDO
	*/
	protected $mysqlConnection;

    /**
     * @var integer
     */
    protected $itemsPerRun = 10;


    /**
     * Cache already retrieved fullTexts
     *
     * @var bool
     */
    protected $useFullTextCache = TRUE;


    /**
     * @var FullTextCache
     */
    protected $fullTextCache;



	public function run() {

        if($this->useFullTextCache) $this->fullTextCache = FullTextCache::getInstance();

        $this->loadConfigs();
		$this->openMySQLConnection();
		$this->processArticles();

        if($this->useFullTextCache) $this->fullTextCache->shutdown();
	}



    protected function loadConfigs() {

        $localConfiguration = array();
        $feedConfiguration = array();

        if(!file_exists(__DIR__ . '/LocalConfiguration.php')) Throw new Exception('The file LocalConfiguration.php has to be existent within the directory ' . __DIR__);
        if(!file_exists(__DIR__ . '/FeedConfiguration.php')) Throw new Exception('The file FeedConfiguration.php has to be existent within the directory ' . __DIR__);

        include __DIR__ . '/LocalConfiguration.php';
        include __DIR__ . '/FeedConfiguration.php';

        $this->itemsPerRun = $localConfiguration['itemsPerRun'];

        $this->mysqlHost = $localConfiguration['mysqlHost'];
        $this->mysqlUser = $localConfiguration['mysqlUser'];
        $this->mysqlPassword = $localConfiguration['mysqlPassword'];
        $this->mysqlFeverDb = $localConfiguration['mysqlFeverDb'];

        $this->feedConfiguration = $feedConfiguration;
    }



	protected function openMySQLConnection() {
        $this->mysqlConnection = new PDO('mysql:host='.$this->mysqlHost.';port=3306;dbname=' . $this->mysqlFeverDb, $this->mysqlUser, $this->mysqlPassword);
	}


	protected function processArticles() {
		$items = $this->getItemsToProcess();

        $itemsInThisRun = 0;

		foreach($items as $item) {

            $url = $item['link'];
            $feedConfig = $this->getConfigForURL($url);

            if($feedConfig) {

                if($this->useFullTextCache && $this->fullTextCache->itemExists($item['uid'])) {
                    $item = $this->addFullTextToItem($item, $this->fullTextCache->get($item['uid']), $feedConfig->getKeepAbstract());
                    $this->persistItem($item);
                    echo "Set fullText for item " . $item['link'] . " from cache. \n";
                } else {

                    if($feedConfig->getXPath()) {
                        $fullText = $this->getItemFulltextFromPage($url, $feedConfig->getXPath());

                        if(trim($fullText)) {

                            // Replace patterns in fulltext
                            if(is_array($feedConfig->getReplace()) && count($feedConfig->getReplace()) == 2) {
                                $replaceArray = $feedConfig->getReplace();
                                $fullText = str_replace($replaceArray[0],$replaceArray[1], $fullText);
                            }

                            $item = $this->addFullTextToItem($item, $fullText, $feedConfig->getKeepAbstract());
                            $this->persistItem($item);
                            if($this->useFullTextCache) $this->fullTextCache->store($item['uid'], $fullText);
                        }

                        $itemsInThisRun++;
                        if($itemsInThisRun >= $this->itemsPerRun) return;
                    }
                }
            }
        }
	}



    /**
     * @param $item
     * @param $fullText
     * @param $keepAbstract
     */
    protected function addFullTextToItem(&$item, $fullText, $keepAbstract) {

        $abstract = $keepAbstract ? $item['description'] : '';
        $newDescriptionPattern = '%s<!--FULLTEXT--><hr><br/><br/>%s';

        $item['description'] = sprintf($newDescriptionPattern, $abstract, $fullText);
        return $item;
    }



    /**
     * @param $url
     * @return feedConfig
     */
    protected function getConfigForURL($url) {

        foreach($this->feedConfiguration as $urlRegex => $config) {
            if(preg_match($urlRegex, $url)) {
                return new feedConfig($urlRegex, $config);
            }
        }

        return NULL;
	}



    /**
     * @return array
     */
    protected function getItemsToProcess() {

		$statement = "SELECT * FROM `fever_items`
						WHERE `read_on_time` = 0
						AND description NOT like '%<!--FULLTEXT-->%'";

        return $this->mysqlConnection->query($statement)->fetchAll();
	}



    /**
     * @param $url
     * @param $xPathQuery
     * @return bool|string
     */
    protected function getItemFulltextFromPage($url, $xPathQuery) {

        if($xPathQuery) {

            echo "Retrieve FullText for $url .. ";

            $dom = new DOMDocument();
            $htmlSource = $this->loadHTMLData($url);
            $success = @$dom->loadHTML($htmlSource);

            if($success) {
                $domXPath = new DOMXPath($dom);

                $resultRows = $domXPath->query($xPathQuery);

                $itemFullText = $this->getInnerHTML($resultRows->item(0));

                echo sprintf("(Full Page %s Chars - Extracted %s Chars) DONE.  \n", strlen($htmlSource), strlen($itemFullText));

                return $itemFullText;

            } else {
                echo "Error while parsing HTML Content for URL $url";
            }
        }

        return FALSE;
	}


    /**
     * @param $node
     * @return string
     */
    protected function getInnerHTML($node) {
        $innerHTML= '';

        if(!$node instanceof DOMNode) return NULL;
        $children = $node->childNodes;

        if(count($children)) {
            foreach ($children as $child) {
                $innerHTML .= $child->ownerDocument->saveXML( $child );
            }
        }

        return $innerHTML;
    }



    /**
     * @param $url
     * @return string
     */
    protected function loadHTMLData($url) {
        $html = file_get_contents($url);
        
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");
        
        return $html;
    }



    /**
     * @param $item
     */
    protected function persistItem($item) {
        $query = $this->mysqlConnection->prepare('UPDATE `fever_items` SET `description` = :description WHERE `id` = :id');
        $query->execute(array('description' => $item['description'], 'id' => $item['id']));
    }

}



/**
 * Class feedConfig
 *
 * Configuration Objects for Feed URLS
 */
class feedConfig {

    protected $url;
    protected $xPath;
    protected $keepAbstract = FALSE;
    protected $replace = NULL;


    public function __construct($url, $configArray) {
        if(!trim($url)) throw new Exception('No URL was given to construct config object');
        if(!trim($configArray['xPath'])) throw new Exception('No xPath was given to retrieve fulltext');

        $this->url = $url;
        $this->xPath = $configArray['xPath'];
        if(array_key_exists('keepAbstract', $configArray)) $this->keepAbstract = $configArray['keepAbstract'];
        if(array_key_exists('replace', $configArray)) $this->replace = $configArray['replace'];
    }


    /**
     * @return boolean
     */
    public function getKeepAbstract() {
        return $this->keepAbstract;
    }

    /**
     * @return null
     */
    public function getReplace() {
        return $this->replace;
    }

    /**
     * @return mixed
     */
    public function getUrl() {
        return $this->url;
    }

    /**
     * @return mixed
     */
    public function getXPath() {
        return $this->xPath;
    }
}


class FullTextCache {

    /**
     * @var array
     */
    protected $items = array();


    /**
     * @var string
     */
    protected $cacheFilePath = '';



    /**
     * @return FullTextCache
     */
    public static function getInstance() {
        $cache = new self;
        $cache->loadCache();
        return $cache;
    }



    public function store($uid, $data) {
        $this->items[$uid] = array('data' => $data, 'used' => TRUE);
    }


    public function get($uid) {
        if(array_key_exists($uid, $this->items)) {
            $this->items[$uid]['used'] = TRUE;
            return $this->items[$uid]['data'];
        }
    }


    /**
     * @param $uid
     * @return bool
     */
    public function itemExists($uid) {
        return array_key_exists($uid, $this->items);
    }



    public function shutdown() {
        $this->compact();
        $this->save();
    }

    protected function __construct() {
        $this->cacheFilePath = __DIR__ . '/FullText.cache';
        $this->loadCache();
    }



    /**
     * Load the cache data
     */
    protected function loadCache() {

       if(file_exists($this->cacheFilePath) && is_readable($this->cacheFilePath) && filesize($this->cacheFilePath)) {
           $serializedCacheData = file_get_contents($this->cacheFilePath);
           $this->items = unserialize($serializedCacheData);
       }
    }


    protected function compact() {
        foreach($this->items as $key => $item) {
            if(!$item['used']) {
                unset($this->items[$key]);
            }
        }
    }


    protected function save() {
        $result = file_put_contents($this->cacheFilePath, serialize($this->items));
        if($result === FALSE) throw new Exception('Cache File ' . $this->cacheFilePath . ' was not writable.', 1379059123);
    }
}


$feverFullFeed = new FeverFullFeed();
$feverFullFeed->run();

?>