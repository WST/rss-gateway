<?php

/**
* RSS reading component
* Made for rss.jsmart.web.id
* © 2011 Ilja I. Averkov <admin@jsmart.web.id>
*/

class Curl
{
	public $headers;
	protected $data;
	public $limit = NULL;
	public $error = '';
	protected $fd;
	protected $fsize;
	
	public function __construct() {
	}
	
	protected function acceptHeaders($ch, $header) {
		if(preg_match('#^HTTP/1\.\\d\\s+404#', $header)) {
			$this->error = '404 Object not found';
			return -1;
		}
		
		if(($i = strpos($header, ':')) !== false) {
			$name = strtolower(trim(substr($header, 0, $i)));
			$value = trim(substr($header, $i + 1));
			$this->headers[$name] = $value;
			
			if(!is_null($this->limit) && $name === 'content-length' && $value > $this->limit) {
				$this->error = 'The content is too large';
				return -1;
			}
		}
		return strlen($header);
	}
	
	protected function acceptData($ch, $chunk) {
		$this->data .= $chunk;
		
		if(!is_null($this->limit) && strlen($this->data) > $this->limit) {
			$this->error = 'The content is too large';
			return -1;
		}
		
		return strlen($chunk);
	}
	
	public function fetch($url) {
		$this->headers = array();
		$this->data = '';
		$this->error = '';
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'jSmartRSS/' . VERSION);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'acceptHeaders'));
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'acceptData'));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		
		if(!curl_exec($ch)) {
			throw new Exception(curl_error($ch));
		}
		
		curl_close($ch);
		
		return $this->data;
	}
	
	public function setMaxSize($size) {
		$this->limit = $size;
	}
}

?>