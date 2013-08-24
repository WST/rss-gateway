<?php

/**
* RSS reading component
* Made for rss.jsmart.web.id
* © 2011 Ilja I. Averkov <admin@jsmart.web.id>
*/

class RSSFeedItem
{
	private $item = NULL;
	private $timestamp = 0;
	private $description = '';
	private $hash = '';
	
	public function __construct(DOMNode & $item) {
		$this->item = & $item;
		
		$pubDate = $item->getElementsByTagName('pubDate');
		if(!$pubDate->length) {
			$pubDate = $item->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'date');
			if(!$pubDate->length) {
				throw new Exception('Item pubDate or dc:date has not been found');
			}
		}
		$this->timestamp = strtotime($pubDate->item(0)->textContent);
		
		$guid = $item->getElementsByTagName('guid');
		if(!$guid->length) {
			$link = $item->getElementsByTagName('link');
			if(!$link->length) {
				throw new Exception('Item guid OR link has not been found');
			}
			$this->hash = md5(trim(strip_tags($link->item(0)->textContent)));
		} else {
			$this->hash = md5(trim(strip_tags($guid->item(0)->textContent)));
		}
		
		$description = $item->getElementsByTagName('description');
		if(!$description->length) {
			throw new Exception('Item description has not been found');
		}
		$this->description = trim(strip_tags($description->item(0)->textContent));
		
		if($this->description == '') {
			$content = $item->getElementsByTagName('content');
			if(!$content->length) {
				throw new Exception('Item description content has not been found');
			}
			$this->description = trim(strip_tags($content->item(0)->textContent));
		}
	}
	
	private function replacer($s) {
		return $this->utf8Char($s[1]);
	}
	
	private function hexReplacer($s) {
		return $this->utf8Char(hexdec($s[1]));
	}
	
	private function stringToUtf8($str) {
		$str = preg_replace_callback('/&#([0-9]+);/', array($this, 'replacer'), $str);
		$str = preg_replace_callback('/&#x([a-f0-9]+);/i', array($this, 'hexReplacer'), $str);
		return $str;
	}
	
	private function utf8Char($num) {
		if($num < 128) {
			return chr($num);
		}
		if($num < 2048) {
			return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
		}
		if($num < 65536) {
			return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
		}
		if($num < 2097152) {
			return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
		}
		return '';
	}
	
	public function timestamp() {
		return $this->timestamp;
	}
	
	public function description() {
		return $this->stringToUtf8($this->description);
	}
	
	public function hash() {
		return $this->hash;
	}
}

?>