#!/usr/bin/php
<?php

/**
* RSS reading component
* Made for rss.jsmart.web.id
* © 2011 Ilya I. Averkov <admin@jsmart.web.id>
*/

if(version_compare(PHP_VERSION, '5.3.0') < 0) {
	die('PHP 5.3 or later is required. Your PHP version is ' . PHP_VERSION);
}

// Реализуем возможность запуска в виде сервиса
if($daemon = (in_array('--daemonize', $argv) || in_array('-d', $argv))) {
	if(!function_exists('pcntl_fork')) {
		die("PHP should be compiled --with-pcntl to be able to use daemonizing!\n");
	}
	if($pid = pcntl_fork()) {
		die("Started with pid: $pid\n");
	}
} else {
	echo "Tip: use --daemonize or -d flag to run in background.\n";
}

define('VERSION', '0.2');

// Зависимость самого RSS-транспорта проверим сразу же
function_exists('curl_init') || die("error: PHP curl bindings are not available!\n");

// Устанавливаем некоторые настройки окружения
ini_set('display_errors', $daemon ? 'Off' : 'On');
ini_set('display_startup_errors', 'On');
error_reporting($daemon ? 0 : E_ALL);
set_time_limit(0);

// Подключаем php-component и части транспорта
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/inc/curl.inc.php';
require __DIR__ . '/inc/rss_feed_item.inc.php';
require __DIR__ . '/inc/rss_feed.inc.php';

class RSSGateway
{
	private $config = array();
	private $component = NULL;
	private $db = NULL;
	private $daemonize = false;
	private $statements = array();
	private $feeds = array();
	private $onliners = array();
	private $stats = array();
	
	public function __construct($daemonize = false) {
		$this->daemonize = $daemonize;
		$this->initializeConfiguration();
		$this->initializeCore();
		$this->initializeDatabase();
		$this->updateStats();
	}
	
	private function initializeConfiguration() {
		(($exists = @ file_exists($f = __DIR__ . '/rss.ini')) && @ is_readable($f)) ? ($this->config = @ parse_ini_file($f, true)) : die('Configuration file rss.ini ' . ($exists ? "cannot be read\n" : "does not exist\n"));
		@ date_default_timezone_set($this->config['daemon']['timezone']);
		if(!isset($this->config['rss']['max_length']) || (int) $this->config['rss']['max_length'] === 0) {
			die("Directive max_length (section [rss]) should be present\n");
		}
		if(!isset($this->config['rss']['max_length']) || (int) $this->config['rss']['refresh_interval'] < 60) {
			die("Directive refresh_interval (section [rss]) should be present and should not be less than 60 (600 is recommended)\n");
		}
	}
	
	private function initializeDatabase() {
		try {
			$this->db = new PDO('sqlite:' . __DIR__ . '/data/rss.db');
			$this->db->exec('CREATE TABLE IF NOT EXISTS feeds (id_feed INTEGER PRIMARY KEY AUTOINCREMENT, feed_userjid VARCHAR(255), feed_url VARCHAR(255), UNIQUE (feed_userjid, feed_url))');
			$this->db->exec('CREATE TABLE IF NOT EXISTS settings (property_userjid VARCHAR(255), property_name VARCHAR(32), property_value INTEGER, UNIQUE (property_userjid, property_name))');
			$this->db->exec('CREATE TABLE IF NOT EXISTS hash_cache (feed_url VARCHAR(255), feed_item_hash VARCHAR(32), UNIQUE (feed_url, feed_item_hash))');
			$this->db->exec('DELETE FROM hash_cache');
		} catch(PDOException $e) {
			die("PDO error: {$e->getMessage()}\n");
		}
		
		$this->statements['stats'] = $this->db->prepare('SELECT COUNT(DISTINCT feed_userjid) AS u, COUNT(*) AS a, COUNT(DISTINCT feed_url) AS f FROM feeds');
		$this->statements['delete_feed'] = $this->db->prepare('DELETE FROM feeds WHERE feed_userjid = :feed_userjid AND id_feed = :id_feed');
		$this->statements['load_feeds'] = $this->db->prepare('SELECT DISTINCT feed_url FROM feeds');
		$this->statements['new_feed'] = $this->db->prepare('REPLACE INTO feeds (feed_userjid, feed_url) VALUES (:jid, :url)');
		$this->statements['get_user_feeds'] = $this->db->prepare('SELECT * FROM feeds WHERE feed_userjid = :jid');
		$this->statements['get_feed_by_id'] = $this->db->prepare('SELECT feed_url FROM feeds WHERE id_feed = :id AND feed_userjid = :jid');
		$this->statements['fetch_user_parameter'] = $this->db->prepare('SELECT property_value FROM settings WHERE property_userjid = :jid AND property_name = :name');
		$this->statements['set_user_parameter'] = $this->db->prepare('REPLACE INTO settings (property_userjid, property_name, property_value) VALUES (:jid, :name, :value)');
	}
	
	private function initializeCore() {
		try {
			$this->component = new BSM\Component (
				@ $this->config['xmpp']['hostname'],
				@ $this->config['xmpp']['port'],
				@ $this->config['xmpp']['component_name'],
				@ $this->config['xmpp']['password']
			);
		} catch(\Exception $e) {
			die("php-component error: {$e->getMessage()}\n");
		}
		
		$this->component->setLoggingMode($this->daemonize ? PHP_COMPONENT_LOG_FILE : PHP_COMPONENT_LOG_CONSOLE, $this->config['daemon']['log']);

		$this->component->setSoftwareVersion('jSmartRSS (http://jsmart.web.id)', VERSION);
		$this->component->setComponentCategory('gateway');
		$this->component->setComponentType('rss');
		$this->component->setComponentTitle(@ $this->config['xmpp']['component_title']);

		$this->component->registerPresenceHandler(array($this, 'myPresenceHandler'));
		$this->component->registerMessageHandler(array($this, 'myMessageHandler'));
		$this->component->registerSuccessfulHandshakeHandler(array($this, 'myHandshakeHandler'));
		$this->component->registerStreamErrorHandler(array($this, 'myErrorHandler'));
		$this->component->registerInbandRegistrationHandler(array($this, 'myRegistrationHandler'));
		$this->component->registerIQVCardHandler(array($this, 'myVCardHandler'));
		$this->component->registerCommandHandler('stats', 'Gateway statistics', array($this, 'myStatsCommandHandler'));
		$this->component->registerCommandHandler('settings', 'Personal settings', array($this, 'mySettingsCommandHandler'));

		$this->component->form()->setTitle('Registration');
		$this->component->form()->setInstructions('Select a preset RSS feed or provide a custom feed URL. In the future, if you need to add another feed, just register once again. To delete a feed just delete it’s contact from your roster.');
		
		$preset = array();
		@ include __DIR__ . '/preset_feeds.inc.php';
		
		$this->component->form()->insertList('preset', 'Popular feeds', array('Custom URL'=>'custom') + $preset, '');
		$this->component->form()->insertLineEdit('url', 'Feed URL', '', false);
	}
	
	public function mySettingsCommandHandler(BSM\Stanza $stanza) {
		$command = $stanza->command();
		$sender = $stanza->from()->bare();
		
		if($command->cancelled()) {
			// Пользователь нажал «отмена»
			return $stanza->reply()->sendCommand($command->cancel());
		}
		
		if($command->hasForm()) {
			$form = $command->form();
			$this->setUserParameter($sender, 'use_chat_type', $form->value('use_chat_type', false) ? '1' : '0');
			$this->setUserParameter($sender, 'read_max_items', (int) $form->value('read_max_items', 10));
			return $stanza->reply()->sendCommand($command->done('Personal settings', 'Your settings have been saved'));
		}
		
		// Здесь нужно вывести форму настроек
		$command->setStatus('executing');
		$command->createForm('form');
		$command->form()->setTitle('Personal settings');
		$command->form()->setInstructions('Configure gateway behavior to suit your needs');
		$command->form()->insertCheckBox('use_chat_type', 'Use chat messages instead of headline', $this->fetchUserParateter($sender, 'use_chat_type', false), true);
		$items = array('1' => '1', '5' => '5', '10' => '10', '20' => '20', '30' => '30', '40' => '40', '50' => '50') + array(($current = $this->fetchUserParateter($sender, 'read_max_items', 10)) => $current);
		$command->form()->insertList('read_max_items', 'Read items', $items, $current, true);
		$stanza->reply()->sendCommand($command);
	}
	
	public function myStatsCommandHandler(BSM\Stanza $stanza) {
		$command = $stanza->command();
		$command->createForm('result');
		$command->setStatus('completed');
		$command->form()->setTitle('Gateway stats');
		$command->form()->insertLineEdit('users_total', 'Registered users', $this->stats[0]);
		$command->form()->insertLineEdit('users_online', 'Online users', $this->stats[1]);
		$command->form()->insertLineEdit('feeds_total', 'Total feeds', $this->stats[2]);
		$command->form()->insertLineEdit('feeds_unique', 'Unique feeds', $this->stats[3]);
		$command->form()->insertLineEdit('uptime', 'Gateway uptime', "{$this->component->uptime()} seconds");
		return $stanza->reply()->sendCommand($command);
	}
	
	public function myVCardHandler(BSM\Stanza $stanza) {
		$stanza->reply()->sendVCard('<vCard xmlns="vcard-temp"><NICKNAME>' . @ $this->config['xmpp']['component_title'] . '</NICKNAME><URL>http://jsmart.web.id</URL><PHOTO><TYPE>image/png</TYPE><BINVAL>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</BINVAL></PHOTO></vCard>');
	}
	
	public function myHandshakeHandler(BSM\Stanza $stanza) {
		$this->component->log('successfully connected to Jabber/XMPP server', PHP_COMPONENT_MESSAGE_WARNING);
		$this->loadFeeds();
		$this->scheduleRefresh();
	}

	public function myErrorHandler(BSM\Stanza $stanza) {
		$this->component->log('stream error, check your component password', PHP_COMPONENT_MESSAGE_ERROR);
	}

	public function myPresenceHandler(BSM\Stanza $stanza) {
		$stanza_type = $stanza->type();
		$sender = $stanza->from()->full();
		$current_time = time();
			
		// TODO: по идее после запуска транспорта он должен рассылать своим пользователям presence probes
		
		if($stanza_type == 'unavailable') {
			unset($this->onliners[$sender]);
			// Unavailable presence приходит всем лентам. Это нормально, что это сообщение может появиться несколько раз
			$this->component->log("user is offline <{$stanza->from()}>", PHP_COMPONENT_MESSAGE_WARNING);
			$this->updateStats();
			return NULL;
		}
		
		if($stanza_type == 'unsubscribe') {
			$m = array();
			if(preg_match('#^feed_([0-9]+)$#', $stanza->to()->username(), $m)) {
				$this->statements['delete_feed']->bindParam(':feed_userjid', $stanza->from()->bare());
				$this->statements['delete_feed']->bindParam(':id_feed', $m[1]);
				$this->statements['delete_feed']->execute();
				$this->statements['delete_feed']->closeCursor();
				$this->component->log("user <{$stanza->from()->bare()}> has deleted feed contact <feed_{$m['1']}>", PHP_COMPONENT_MESSAGE_WARNING);
			}
		}
		
		if($stanza_type == 'subscribe') {
			$stanza->reply()->subscribedPresence($stanza->to()->username());
			$stanza->reply()->sendNewRosterItem($stanza->to()->username());
			$stanza->reply()->sendPresence($stanza->to()->username());
		}
		
		if($stanza_type == 'subscribed') {
			$stanza->reply()->sendPresence($stanza->to()->username());
			$this->updateStats();
		}
		
		if(@ $this->onliners[$sender] > ($current_time = time()) - 1) {
			return NULL;
		}
		
		if($stanza->from()->resource() != '') {
			$this->component->log("new onliner <{$stanza->from()}>", PHP_COMPONENT_MESSAGE_WARNING);
			$this->onliners[$sender] = $current_time;
			foreach($this->getUserFeeds($stanza->from()->bare()) as $feed_id => $feed_url) {
				$stanza->reply()->sendPresence("feed_$feed_id");
			}
			$this->updateStats();
		}
	}
	
	private function fetchUserParateter($jid, $param_name, $default_value) {
		$this->statements['fetch_user_parameter']->bindParam(':jid', $jid);
		$this->statements['fetch_user_parameter']->bindParam(':name', $param_name);
		$this->statements['fetch_user_parameter']->execute();
		$result = ($row = $this->statements['fetch_user_parameter']->fetch()) ? $row['property_value'] : $default_value;
		$this->statements['fetch_user_parameter']->closeCursor();
		return $result;
	}
	
	private function setUserParameter($jid, $param_name, $param_value) {
		$this->statements['set_user_parameter']->bindParam(':jid', $jid);
		$this->statements['set_user_parameter']->bindParam(':name', $param_name);
		$this->statements['set_user_parameter']->bindParam(':value', $param_value);
		$this->statements['set_user_parameter']->execute();
	}
	
	private function getFeedById($jid, $id) {
		$retval = NULL;
		$this->statements['get_feed_by_id']->bindParam(':jid', $jid);
		$this->statements['get_feed_by_id']->bindParam(':id', $id);
		$this->statements['get_feed_by_id']->execute();
		if($row = $this->statements['get_feed_by_id']->fetch()) {
			$retval = $this->feeds[$row['feed_url']];
		}
		$this->statements['get_feed_by_id']->closeCursor();
		return $retval;
	}
	
	private function preferredMessageType($jid) {
		return $this->fetchUserParateter($jid, 'use_chat_type', 0) ? 'chat' : 'headline';
	}

	public function myMessageHandler(BSM\Stanza $stanza) {
		
		if(@ $stanza->type() == 'error') {
			return false;
		}
		
		if(($text = $stanza->body()) == '') {
			return false;
		}
		
		if(!isset($this->onliners[$sender = $stanza->from()->full()]) || $this->onliners[$sender] > ($current_time = time()) - 1) {
			// Последняя активность была менее 1 секунды назад, нехорошо
			return NULL;
		}
		if($stanza->type() == 'chat') {
			$this->onliners[$sender] = $current_time;
			switch($stanza->body()) {
				case 'about': $stanza->reply()->sendMessage('This gateway uses jSmartRSS ' . VERSION . ', XMPP to RSS gateway. jSmartRSS is a product by SmartCommunity. For more information visit http://jsmart.web.id or join xmpp:support@conference.jsmart.web.id?join', $stanza->to()->username(), 'chat'); break;
				case 'read':
					$m = array();
					if(preg_match('#^feed_([0-9]+)$#', $stanza->to()->username(), $m)) {
						if(!is_null($feed = $this->getFeedById($stanza->from()->bare(), $m[1]))) {
							foreach($feed->asArray($this->fetchUserParateter($stanza->from()->bare(), 'read_max_items', 10)) as $item) {
								$this->component->sendMessage($item->description(), $sender, $stanza->to()->username(), $this->preferredMessageType($stanza->from()->bare()));
							}
						} else {
							$this->component->sendMessage('Feed does not exist', $sender, $stanza->to()->username(), 'chat');
						}
					}
				break;
				default: $stanza->reply()->sendMessage("\nThis gateway sends new items automatically when they appear in the corresponding feed.\n\n-- Supported commands --\nread — view current feed content\nabout — information about this gateway\n\n-- Configuration --\nYou can configure gateway behavior using ad-hoc command API.", $stanza->to()->username(), 'chat'); break;
			}
		}
	}
	
	public function loadFeeds() {
		$this->component->log('starting to load feeds', PHP_COMPONENT_MESSAGE_INFO);
		$this->statements['load_feeds']->execute();
		while($row = $this->statements['load_feeds']->fetch()) {
			try {
				$this->feeds[$row['feed_url']] = new RSSFeed($row['feed_url'], $this->db);
				$this->feeds[$row['feed_url']]->setMaxLength((int) $this->config['rss']['max_length']);
				$this->feeds[$row['feed_url']]->refresh();
			} catch(Exception $e) {
				$this->component->log($e->getMessage(), PHP_COMPONENT_MESSAGE_WARNING);
				continue;
			}
		}
		$this->statements['load_feeds']->closeCursor();
		$this->component->log('completed loading feeds', PHP_COMPONENT_MESSAGE_INFO);
	}
	
	public function scheduleRefresh() {
		$this->component->setTimeout((int) $this->config['rss']['refresh_interval'], array($this, 'refreshFeeds'));
	}
	
	private function getUserFeeds($jid) {
		$retval = array();
		$this->statements['get_user_feeds']->bindParam(':jid', $jid);
		$this->statements['get_user_feeds']->execute();
		while($row = $this->statements['get_user_feeds']->fetch()) {
			$retval[$row['id_feed']] = $row['feed_url'];
		}
		$this->statements['get_user_feeds']->closeCursor();
		return $retval;
	}
	
	public function refreshFeeds() {
		foreach($this->feeds as $url => $feed) {
			$this->component->log("refreshing feed <$url>", PHP_COMPONENT_MESSAGE_INFO);
			try {
				$feed->refresh();
			} catch(Exception $e) {
				$this->component->log($e->getMessage(), PHP_COMPONENT_MESSAGE_WARNING);
				continue;
			}
		}
		foreach($this->onliners as $jid => $timestamp) {
			$jid = $this->component->parseJID($jid);
			foreach($this->getUserFeeds($jid->bare()) as $feed_id => $feed_url) {
				if(!is_object($this->feeds[$feed_url])) {
					continue;
				}
				foreach($this->feeds[$feed_url]->newItems() as $item) {
					$this->component->sendMessage($item->description(), $jid, "feed_$feed_id", 'headline');
				}
			}
			$this->onliners[$jid->full()] = time();
		}
		$this->scheduleRefresh();
	}
	
	public function newFeed($jid, $url) {
		try {
			if(!isset($this->feeds[$url])) {
				$this->feeds[$url] = new RSSFeed($url, $this->db);
				$this->feeds[$url]->setMaxLength((int) $this->config['rss']['max_length']);
				$this->feeds[$url]->refresh();
			}
		} catch(Exception $e) {
			$this->component->log($e->getMessage(), PHP_COMPONENT_MESSAGE_WARNING);
			return false;
		}
		$this->statements['new_feed']->bindParam(':jid', $jid);
		$this->statements['new_feed']->bindParam(':url', $url);
		$this->statements['new_feed']->execute();
		return $this->db->lastInsertId();
	}
	
	public function myRegistrationHandler(BSM\Stanza $stanza) {
		if(($url = $stanza->form()->value('url')) == '') {
			$url = $stanza->form()->value('preset');
		}
		
		if(preg_match('#^http://.+$#iU', $url) && ($id = $this->newFeed($stanza->from()->bare(), $url))) {
			$stanza->reply()->registrationSuccess();
			$stanza->reply()->sendNewRosterItem("feed_$id");
			return $this->component->log("successful registration from <{$stanza->from()}>", PHP_COMPONENT_MESSAGE_WARNING);
		}
		
		$stanza->reply()->registrationFailure();
		$this->component->log("failed registration from <{$stanza->from()}>", PHP_COMPONENT_MESSAGE_WARNING);
	}
	
	public function updateStats() {
		$this->statements['stats']->execute();
		$row = $this->statements['stats']->fetch(PDO::FETCH_ASSOC);
		$this->statements['stats']->closeCursor();
		
		$this->component->setStats('users/total', $this->stats[0] = $row['u'], 'users');
		$this->component->setStats('users/online', $this->stats[1] = count($this->onliners), 'users');
		$this->component->setStats('feeds/total', $this->stats[2] = $row['a'], 'feeds');
		$this->component->setStats('feeds/unique', $this->stats[3] = $row['f'], 'feeds');
	}
	
	public function execute() {
		while(($result = $this->component->run()) !== 0) {
			$this->component->log('disconnected. Trying to connect again in 10 seconds', PHP_COMPONENT_MESSAGE_WARNING);
			sleep(10);
		}
		$this->component->log('shutting down');
	}
}

$mycomponent = new RSSGateway($daemon);
$mycomponent->execute();

?>