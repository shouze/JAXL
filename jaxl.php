<?php
/**
* Jaxl (Jabber XMPP Library)
*
* Copyright (c) 2009-2012, Abhinav Singh <me@abhinavsingh.com>.
* All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions
* are met:
*
* * Redistributions of source code must retain the above copyright
* notice, this list of conditions and the following disclaimer.
*
* * Redistributions in binary form must reproduce the above copyright
* notice, this list of conditions and the following disclaimer in
* the documentation and/or other materials provided with the
* distribution.
*
* * Neither the name of Abhinav Singh nor the names of his
* contributors may be used to endorse or promote products derived
* from this software without specific prior written permission.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
* "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
* LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
* FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
* COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
* BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
* LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
* CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRIC
* LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
* ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
*/

declare(ticks = 1);
define('JAXL_CWD', __DIR__);

require_once JAXL_CWD.'/xmpp/xmpp_stream.php';
require_once JAXL_CWD.'/core/jaxl_event.php';

/**
 * Jaxl class extends base XMPPStream class with following functionalities:
 * 1) Adds an event based wrapper over xmpp stream lifecycle
 * 2) Provides restart strategy and signal handling to ensure connectivity of xmpp stream
 * 3) Roster management as specified in XMPP-IM
 * 4) Management of XEP's inside xmpp stream lifecycle
 * 5) Adds a logging facility
 * 6) Adds a cron job facility in sync with connected xmpp stream timeline
 * 
 * @author abhinavsingh
 *
 */
class JAXL extends XMPPStream {
	
	// lib meta info
	const version = '3.0.0-alpha-1';
	const name = 'JAXL :: Jabber XMPP Library';
	
	// cached init config array
	protected $cfg = array();
	
	// event callback engine for xmpp stream lifecycle
	protected $ev = null;
	
	// reference to various xep instance objects
	protected $xeps = array();
	
	// local cache of roster list
	public $roster = array();
	
	// whether jaxl must also populate local roster cache with
	// received presence information about the contacts
	public $manager_roster = true;
	
	// automatically accept new subscription requests
	public $auto_accept_subscribe = false;
	
	// path variables
	public $tmp_path;
	public $log_path;
	public $pid_path;
	public $pid;
	
	// local ip address
	public $local_ip;
	
	// cgi | cli
	public $mode;
	
	// periodically dump stats
	public $dump_stats = true;
	
	// current status message
	public $status;
	
	// identity
	public $features = array();
	public $category = 'client';
	public $type = 'bot';
	public $lang = 'en';
	
	// after cth failed attempt
	// retry connect after k * $retry_interval seconds
	// where k is a random number between 0 and 2^c - 1.
	/*public $retry = true;
	private $retry_interval = 1;
	private $retry_attempt = 0;
	private $retry_max_interval = 32; // 2^5 seconds (means 5 max tries)
	
	else {
		// 110 : Connection timed out
		// 111 : Connection refused
		if($this->sock->errno == 110 || $this->sock->errno == 111) {
			$retry_after = pow(2, $this->retry_attempt) * $this->retry_interval;
			$this->retry_attempt++;
	
			echo "unable to connect, will try again in ".$retry_after." seconds\n";
			// use sigalrm instead (if possible)
			sleep($retry_after);
			$this->start_client();
		}
	}*/
	
	public function signal_handler($sig) {
		$this->end_stream();
		
		switch($sig) {
			// terminal line hangup
			case SIGHUP:
				echo "got sighup\n";
				break;
				// interrupt program
			case SIGINT:
				echo "got sigint\n";
				break;
				// software termination signal
			case SIGTERM:
				echo "got sigterm\n";
				break;
		}
	}
	
	public function __construct($config) {
		// handle signals
		pcntl_signal(SIGHUP, array($this, 'signal_handler'));
		pcntl_signal(SIGINT, array($this, 'signal_handler'));
		pcntl_signal(SIGTERM, array($this, 'signal_handler'));
		
		// save config
        $this->cfg = array_merge(array('pass' => null), $config);
		
        if(isset($this->cfg['force_tls']))
            $this->force_tls = $this->cfg['force_tls'];

		// initialize event
		$this->ev = new JAXLEvent();
		
		// initialize xmpp stream
		parent::__construct(
			$this->cfg['jid'], 
			$this->cfg['pass']
		);
	}
	
	public function __destruct() {
		parent::__destruct();
	}
	
	public function require_xep($xeps) {
		foreach($xeps as $xep) {
			$filename = 'xep_'.$xep.'.php';
			$classname = 'XEP_'.$xep;
			
			// include xep
			require_once JAXL_CWD.'/xep/'.$filename;
			$this->xeps[$xep] = new $classname($this);
			
			// add necessary requested callback on events
			foreach($this->xeps[$xep]->init() as $ev=>$cb) {
				$this->add_cb($ev, array($this->xeps[$xep], $cb));
			}
		}
	}
	
	public function add_cb($ev, $cb, $pri=1) {
		return $this->ev->add($ev, $cb, $pri);
	}
	
	public function del_cb($ref) {
		$this->ev->del($ref);
	}
	
	public function set_status($status, $show, $priority) {
		$this->send($this->get_pres_pkt(
			array(),
			$status,
			$show,
			$priority
		));
	}
	
	public function send_chat_msg($to, $body) {
		$msg = new XMPPMsg(array('type'=>'chat', 'to'=>$to, 'from'=>$this->full_jid->to_string()), $body);
		$this->send($msg);
	}
	
	public function start() {
		// if on_connect event have no callbacks
		// set default on_connect callback to $this->start_stream()
		// i.e. xmpp client mode
		if(!isset($this->ev->reg['on_connect']))
			$this->add_cb('on_connect', array($this, 'start_stream'));
		
		// start
		if($this->connect(@$this->cfg['host'], @$this->cfg['port'])) {
			$this->ev->emit('on_connect');
			
			while($this->sock->fd) {
				$this->sock->recv();
			}
			
			$this->ev->emit('on_disconnect');
		}
		else {
			$this->ev->emit('on_connect_error', array(
				$this->sock->errno,
				$this->sock->errstr
			));
		}
	}
	
	//
	// abstract method implementation
	//
	
	public function handle_auth_mechs($mechs) {
		$pref_auth = @$this->cfg['auth_type'] ? $this->cfg['auth_type'] : 'PLAIN';
		$pref_auth_exists = isset($mechs[$pref_auth]) ? true : false;
		
		if($pref_auth_exists) {
			$this->send_auth_pkt($pref_auth, $this->jid->to_string(), $this->pass);
		}
		else {
			echo "preferred auth type not supported\n";
		}
	}
	
	public function handle_auth_success() {
		$this->ev->emit('on_auth_success');
	}
	
	public function handle_auth_failure($reason) {
		$this->ev->emit('on_auth_failure', array(
			$reason
		));
	}
	
	public function handle_stream_start($stanza) {
		$stanza = new XMPPStanza($stanza);
		
		$this->ev->emit('on_stream_start', array($stanza));
		return array('connected', 1);
	}
	
	public function handle_iq($stanza) {
		$stanza = new XMPPStanza($stanza);
	}
	
	public function handle_presence($stanza) {
		$stanza = new XMPPStanza($stanza);
	}
	
	public function handle_message($stanza) {
		$stanza = new XMPPStanza($stanza);
		$this->ev->emit('on_'.$stanza->type.'_message', array($stanza));
	}
	
	// unhandled event and arguments bubbled up
	public function handle_other($event, $args) {
		$stanza = $args[0];
		$stanza = new XMPPStanza($stanza);
		return $this->ev->emit('on_'.$stanza->name.'_stanza', array($stanza));
	}
	
}

?>
