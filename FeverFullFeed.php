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


	public function run() {
        $this->loadConfigs();
		$this->openMySQLConnection();
		$this->processArticles();
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
			$xPathQuery = $this->getConfigForURL($url);

			if($xPathQuery) {
                $fullText = $this->getItemFulltext($url, $xPathQuery);

                if(trim($fullText)) {
				    $item = $this->addFullTextToItem($item, $fullText);
                    $this->persistItem($item);
                }

                $itemsInThisRun++;
                if($itemsInThisRun >= $this->itemsPerRun) return;
			}
        }
	}



    /**
     * @param $item
     * @param $fullText
     */
    protected function addFullTextToItem(&$item, $fullText) {
        $description = $item['description'];
        $newDescriptionPattern = '%s<!--FULLTEXT--><hr><br/><br/>%s';

        $item['description'] = sprintf($newDescriptionPattern, $description, $fullText);
        return $item;
    }



    /**
     * @param $url
     * @return string
     */
    protected function getConfigForURL($url) {

        foreach($this->feedConfiguration as $urlRegex => $xPath) {
            if(preg_match($urlRegex, $url)) {
                return $xPath;
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
    protected function getItemFulltext($url, $xPathQuery) {

        if($xPathQuery) {

            echo "GET FullText for $url .. ";

            $dom = new DOMDocument();
            $success = @$dom->loadHTML($this->loadHTMLData($url));

            if($success) {
                $domXPath = new DOMXPath($dom);

                $resultRows = $domXPath->query($xPathQuery);

                $itemFullText = $this->getInnerHTML($resultRows->item(0));

                echo " DONE.  \n";

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


$feverFullFeed = new FeverFullFeed();
$feverFullFeed->run();

?>