<?php
class MP_Pop3
{
	const bt = 100;

	var $pop3 = null;

	function __construct($server, $port, $username, $password, $trace = false)
	{
		$this->server 	= $server;
		$this->port 	= $port;
		$this->username 	= $username;
		$this->password 	= $password;
		$this->trace	= $trace;
	}

	function get_response($cmd = false)
	{
		if ($cmd)
		{
			fwrite($this->pop3, "$cmd\r\n");
			if ($this->trace)
			{
				if ('PASS ' == substr($cmd, 0, 5)) $cmd = 'PASS ' . str_repeat('*', strlen($cmd) - 5);
				$bm = " POP cmd    ! $cmd";
				$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
			}
		}
		
		$response = '';
		do { $response .= $this->fetch(); } while("\n" != substr($response, -1 ));

		if ($this->trace)
		{
			$bm = " response   ! " . str_replace("\r\n", '', $response); //substr($response, 0, -3);
	
			$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
		}

		if ( ('QUIT' == $cmd) && ('.' == $response[0]) ) 	return $response;
		if ( '+' == $response[0] ) 					return $response;

		$bm = " end        ! Abort";
		$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
		return false;
	}

	function fetch() { return fgets( $this->pop3, 1024 ); }

	function connect()
	{
	// fsockopen 
		$this->pop3 = fsockopen($this->server, $this->port, $errno, $errstr);
		if ($errno) 
		{
			if ($this->trace)
			{
				$bm = "*** ERROR **! $errno $errstr";
				$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
				$bm = " end        ! Abort";
				$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
			}
			return false;
		}
		$response = $this->get_response();
		if (!$response) return false;
	// USER 
		$response = $this->get_response('USER ' . $this->username);
		if (!$response) return false;
	// PASS 
		$response = $this->get_response('PASS ' . $this->password);
		if (!$response) return false;

		return true;
	}

	function disconnect()
	{
		$response = $this->get_response('QUIT');
		fclose($this->pop3);
		if ($this->trace)
		{
			$bm = "disconnected!";
			$this->trace->log('!' . $bm . str_repeat( ' ', self::bt - strlen($bm)) . '!');
		}
		return true;
	}

	function get_list()
	{
		$this->messages = array();
	
		$response = $this->get_response('LIST');
		if (!$response) return false;

		$r = explode(' ', $response);
		if (!$r[1])	return false; // list is empty
		if ($string = $this->fetch()) do { $datas = explode(' ', $string); $this->messages[] = $datas[0]; $string = $this->fetch(); } while(".\r\n" != substr($string, -3 ));
		return true;
	}

	function get_message($id)
	{
		$this->message = '';

		$response = $this->get_response("RETR $id ");
		if (!$response) return false;

		if ($string = $this->fetch()) do { $string .= $this->fetch(); } while(".\r\n" != substr($string, -3 ));
		$this->message = $string;
	}

	function get_headers($id)
	{
		$this->headers = array();
		$response = $this->get_response("TOP $id 0");
		if (!$response) return false;

		if ($string = $this->fetch()) do { $string .= $this->fetch(); } while(".\r\n" != substr($string, -3 ));
		$this->extract_headers($string);
	}

	function get_headers_deep($id)
	{
		$this->headers = array();
		$this->get_message($id);
		$this->extract_headers($this->message);
	}

	function extract_headers($string)
	{
		$raw_headers = preg_replace("/\r\n[ \t]+/", ' ', $string); // Unfold headers
		$raw_headers = explode("\r\n", $raw_headers);
		$this->headers = array();
		foreach ($raw_headers as $value) 
		{
			if ($value == '') continue;
			$k = substr($value, 0, $pos = strpos($value, ':'));
			$v = ltrim(substr($value, $pos + 1));
			if (empty($k)) continue;
			if (empty($v)) continue;
			if (isset($this->headers[$k]) && is_array($this->headers[$k])) 	$this->headers[$k][] = $v;
			elseif (isset($this->headers[$k])) 						$this->headers[$k]   = array($this->headers[$k], $v);
			else 											$this->headers[$k]   = $v;
		}
	}

	function delete($id)
	{
		$response = $this->get_response('DELE ' . $id);
		$this->get_response();
	}
}
?>