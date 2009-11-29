<?

/*
  Author: Kamil Trzcinski
  E-mail: ayufan(at)osk-net(dot)pl
  WWW: http://www.ayufan.eu
  SVN: https://svn.osk-net.pl:444/rosapi (login: guest)
  License: http://www.gnu.org/licenses/gpl.html
*/

class RouterOS
{
	private $sock;
	private $tags;
	private $where;
	
	public $readOnly = FALSE;
	
	private function writeSock($cmd = '') {	
		//if(strlen($cmd) == 0)
		//	echo "<<< ---\n";
		//else
		//	echo "<<< $cmd\n";
		
		$l = strlen($cmd);
		if($l < 0x80) {
			fwrite($this->sock, chr($l));
		}
		else if($l < 0x4000) {
			$l |= 0x8000;
			fwrite($this->sock, chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}	
		else if($l < 0x200000) {
			$l |= 0xC00000;
			fwrite($this->sock, chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		else if($l < 0x10000000) {
			$l |= 0xE0000000;
			fwrite($this->sock, chr(($l >> 24) & 0xFF) . chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		else {
			fwrite($this->sock, chr(0xF0) . chr(($l >> 24) & 0xFF) . chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		
		fwrite($this->sock, $cmd);
	}
	
	private function readSock() {
		$c = ord(fread($this->sock, 1));
		if(($c & 0x80) == 0x00) {
		}
		else if(($c & 0xC0) == 0x80) {
			$c &= ~0xC0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else if(($c & 0xE0) == 0xC0) {
			$c &= ~0xE0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else if(($c & 0xF0) == 0xE0) {
			$c &= ~0xF0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else {
			$c = ord(fread($this->sock));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		
		if($c == 0) {
			//echo ">>> ---\n";
			return NULL;
		}
		
		$o = '';
		while(strlen($o) < $c)
			$o .= fread($this->sock, $c - strlen($o));
			
		//echo ">>> $o\n";	
		return $o;
	}
	
	private function trap($args, $hide = FALSE) {
		if($args['message'] && $args['message'] != 'interrupted')
			echo "trap[".$this->where."]: ${args['message']}\n";
		while($this->response() != '!done');
	}

	static function connect($host, $login, $password, $port = 8728, $timeout = 5) {
		$self = new RouterOS();
	
		// open socket
		if(($self->sock = @fsockopen($host, 8728, $errno, $errstr, $timeout)) === FALSE)
			return NULL;
		stream_set_timeout($self->sock, $timeout);
		
		// initiate login
		$self->send('', 'login');
		$type = $self->response(&$args);
		if($type != '!done' || !isset($args['ret']))
			return NULL;
					
		// try to login
		$self->send('', 'login', FALSE, array('name' => $login, 'response' => '00'.md5(chr(0).$password.pack('H*',$args['ret']))));
		$type = $self->response(&$args);
		if($type == '!done')
			return $self;
		else if($type == '!trap')
			$self->trap($args);
		unset($self);
		return NULL;
	}
	
	public function setTimeout($timeout = 5) {
		return stream_set_timeout($this->sock, $timeout);
	}

	private function send($cmd, $type, $proplist = FALSE, $args = FALSE, $tag = FALSE) {
		if(is_array($cmd))
			$cmd = '/' . join('/', $cmd);
		$cmd .= '/' . $type;
		
		$this->where = $cmd;
			
		// send command & args
		$this->writeSock($cmd);
		if($args) {
			foreach($args as $key=>$value)
				$this->writeSock("=$key=". $value);
		}
		if($proplist)
			$this->writeSock(".proplist=" . (is_array($proplist) ? join(',', $proplist) : $proplist));
		if($tag)
			$this->writeSock(".tag=$tag");
		$this->writeSock();
	}
	
	private function response($args = FALSE, $params = FALSE) {
		$params = array();
		$args = array();
		$type = FALSE;
		
		// read response type
		if($type = $this->readSock()) {
			if($type[0] != '!') {
				while($this->readSock());
				return FALSE;
			}
		}
		
		// read response parameters
		while($line = $this->readSock()) {
			if($line[0] = '=') {
				$line = explode('=', $line, 3);
				$args[$line[1]] = count($line) == 3 ? $line[2] : TRUE;
				continue;
			}
			else {
				$line = explode('=', $line, 2);
				$params[$line[0]] = isset($line[1]) ? $line[1] : '';
			}
		}
		unset($args['debug-info']);
		return $type;
	}
	
	function getall($cmd, $proplist = FALSE, $args = array(), $assoc = FALSE) {
		$this->send($cmd, 'getall', $proplist, $args);

		if($proplist) {
			if(!is_array($proplist))
				$proplist = explode(',', $proplist);
			$proplist = array_fill_keys($proplist, TRUE);
		}
		
		$ids = array();
		
		// wait for response
		while(true) {
			$ret = array();
			switch($type = $this->response(&$ret)) {
				case '!re':
					if($proplist)
						$ret = array_intersect_key($ret, $proplist);
					if(isset($ret['.id']))
						if($assoc)
							$ids[$ret[$assoc]] = $ret;
						else
							$ids[] = $ret;
					else
						foreach($ret as $key => $value)
							$ids[$key] = $value;
					break;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				case '!done':
					break 2;
					
				default:
					die("getall: undefined type=$type\n");
			}
		}
		
		return $ids;
	}
	
	function set($cmd, $args = array()) {
		if($this->readOnly)
			return TRUE;
			
		$this->send($cmd, 'set', FALSE, $args);
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}

	function reboot() {
		$this->send('/system', 'reboot', FALSE, FALSE);

		echo "!! rebooting...\n";
		sleep(5);

		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;

			case '!trap':
				$this->trap($ret);
				return FALSE;

			default:
				return TRUE;
		}
	}
	
	function cancel($tag = FALSE) {	
		$this->send('', 'cancel', FALSE, FALSE, $tag);
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
  
	function fetchurl($url) {
		$finished = FALSE;
		
		echo ".. downloading $url\n";

		$this->send('/tool', 'fetch', FALSE, array('url' => $url));
		
		while(true) {
			switch($type = $this->response(&$ret)) {
				case '!done':
					return TRUE;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				case '!re':
					switch($ret['status']) {
						case 'connecting':
						case 'requesting':
							break;
							
						case 'downloading':
							if(!$ret['total'])
								break;
							$progress = round(intval($ret['downloaded'])*100 / intval($ret['total']), 1);
							echo ".. ${ret['downloaded']} of ${ret['total']} ($progress%) within ${ret['duration']}\n";
							break;
							
						case 'finished':
							echo ".. downloaded!\n";
							$this->cancel();
							$finished = TRUE;
							break;
							
						case 'failed':
							echo "!! failed!\n";
							$this->cancel();
							break;
							
						default:
							die("fetch: undefined response (${ret['status']})\n");
					}
					break;
					
				default:
					die("fetch: undefined type\n");
			} 
			flush();
		}
		return $finished;
	}
	
	function move($cmd, $id, $before) {
		if($this->readOnly)
			return TRUE;
			
		$this->send($cmd, 'move', FALSE, array('numbers' => $id, 'destination' => $before));
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
  
	function add($cmd, $args = array()) {
		if($this->readOnly)
			return TRUE;
			
		$this->send($cmd, 'add', FALSE, $args);
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				if(isset($ret['ret']))
					return $ret['ret'];
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
	
	function remove($cmd, $id) {
		if($this->readOnly)
			return TRUE;
			
		$this->send($cmd, 'remove', FALSE, array('.id' => is_array($id) ? join(',', $id) : $id));
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("remove: undefined type\n");
		}
	}
  
	function unsett($cmd, $id, $value) {
		if($this->readOnly)
			return TRUE;
				
		$this->send($cmd, 'unset', FALSE, array('numbers' => $id, 'value-name' => $value));
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("unset: undefined type\n");
		}
	}
  
	function scan($id, $duration="00:02:00") {
		$this->send('/interface/wireless', 'scan', FALSE, array('.id' => $id, 'duration' => $duration));

		$results = array();
		
		while(true) {
			$ret = array();
			switch($type = $this->response(&$ret)) {
				case '!done':
					return $results;
					
				case '!re':
					$results[$ret['address']] = $ret;
					break;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				default:
					die("scan: undefined type: $type\n");
			}
		}
	}
};

?>