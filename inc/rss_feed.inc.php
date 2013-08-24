<?php

/**
* RSS reading component
* Made for rss.jsmart.web.id
* © 2011 Ilja I. Averkov <admin@jsmart.web.id>
*/

class RSSFeed
{
	private $url;
	private $items = array();
	private $newitems = array();
	private $hashes = array();
	private $max_length = NULL;
	private $loaded = false;
	private $db = NULL;
	private $statements = array();
	
	public function __construct($url, PDO & $db) {
		$this->url = $url;
		$this->db = & $db;
		$this->items = array();
		$this->statements['check'] = $db->prepare('SELECT count(*) AS cnt FROM hash_cache WHERE feed_url = :url AND feed_item_hash = :hash');
		$this->statements['check']->bindParam(':url', $url);
	}
	
	public function setMaxLength($bytes) {
		$this->max_length = $bytes;
	}
	
	public function refresh() {
		$feed = new DOMDocument('1.0', 'utf-8');
		$curl = new Curl();
		
		if(!is_null($this->max_length)) {
			$curl->setMaxSize($this->max_length);
		}
		
		if(! @ $feed->loadXML($curl->fetch($this->url))) {
			throw new Exception("failed to parse <{$this->url}>");
		}
		
		$channel = $feed->getElementsByTagName('channel');
		if(!$channel->length) {
			throw new Exception("failed to parse <{$this->url}>");
		}
		
		$items = $feed->getElementsByTagName('item');
		if(!$items->length) {
			throw new Exception("the feed is empty <{$this->url}>");
		}
		
		$this->newitems = array();
		$this->hashes = array();
		
		for($i = $items->length - 1; $i >= 0; $i --) {
			$item = new RSSFeedItem($items->item($i));
			$hash = $item->hash();
			if($this->loaded && $this->isNew($hash)) {
				$this->newitems[] = $item;
			}
			$index = $items->length - $i - 1;
			$this->items[$index] = $item;
			$this->hashes[$index] = $hash;
		}
		
		$this->loaded = true;
		$this->cacheHashes();
	}
	
	private function cacheHashes() {
		$this->db->beginTransaction();
		foreach($this->hashes as $hash) {
			$this->db->exec("REPLACE INTO hash_cache (feed_url, feed_item_hash) VALUES ('{$this->url}', '$hash')");
		}
		$this->db->commit();
	}
	
	public function & hashes() {
		return $this->hashes;
	}
	
	private function isNew($hash) {
		$this->statements['check']->bindParam(':hash', $hash);
		$this->statements['check']->execute();
		$row = $this->statements['check']->fetch();
		$this->statements['check']->closeCursor();
		return $row['cnt'] == 0;
	}
	
	public function asArray($wanted = 0) {
		return $wanted ? (($wanted >= ($available = count($this->items))) ? $this->items : (array_slice($this->items, $available - $wanted, $wanted, true))) : $this->items;
	}
	
	public function & newItems() {
		return $this->newitems;
	}
}

?>