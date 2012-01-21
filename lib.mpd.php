<?php
namespace qad\mpd;
use UnexpectedValueException, RuntimeException, InvalidArgumentException;

// {{{ ProtocolException, CommandException

class ProtocolException extends UnexpectedValueException
{
	function __construct($msg='FAIL at decoding the MDP protocol. n00b!',Exception $e=null)
	{
		parent::__construct($msg,11,$e);
	}
}

class CommandException extends InvalidArgumentException
{
	function __construct($line,Exception $e=null)
	{
		if( preg_match('/^ACK \[(\d+)@(\d+)\] \{(\w+)\} (.*)$/', $line, $m) )
		{
			$this->file = $m[3];
			$this->line = (integer)$m[2];
			parent::__construct($m[4],$m[1],$e);
		}
		else throw new ProtocolException;
	}
}

// }}}

class Mpd
{
	// {{{ --properties

	private $host = null;
	private $port = null;
	private $pass = null;

	private $con = null; // socket resource

	private $server_version = false;

	private $command_sent = false;

	// }}}
	// {{{ getServerVersion

	function getServerVersion() { return $this->server_version; }

	// }}}
	// {{{ __construct, __get, __set

	function __construct($host='localhost', $port='6600', $pass='hackme')
	{
		assert('is_string($host)');
		assert('is_numeric($port)');
		assert('is_string($pass)');

		$this->host = $host;
		$this->port = $port;
		$this->pass = $pass;
	}

	function __get($member)
	{
		assert('is_string($member)');
		switch(strtolower($member))
		{

			// Commands that do not return data.
		case 'clearerror':
			if( $this->sendCommand(strtolower($member)) and $this->untilOK() )
				return true;
			break;

			// Commands that return one array of data.
		case 'status':
		case 'currentsong':
		case 'idle':
		case 'stats':
			if( $this->sendCommand(strtolower($member)) and $this->extractPairs($o) and assert('is_array($o)') )
				return $o;
			break;

			// Commands that return an list of array of data.
		case 'playlistinfo':
			$e = strtr(strtolower($member),array( // For each command, define the last key used to seperate the array of data
				'playlistinfo' => 'id'));
			if( ( (!$this->command_sent and $this->sendCommand(strtolower($member)))
					or ($this->command_sent) )
				and $this->extractPairs($o,$e) and assert('is_array($o)') )
				return $o;
			break;

		default:
			throw ProtocolException('Command not implemented (or do not have sufficient privileges (or do not exists)).');
		}
		return false;
	}

	function __call($member, $args)
	{
		assert('is_string($member)');
		assert('is_array($args)');
		assert('array_filter($args,"is_scalar")');
		$args = array_map('strval',$args);
		switch(strtolower($member))
		{

			// Commands that do not return data.
		case 'consume':
			if( $this->sendCommand(strtolower($member),$args) and $this->untilOK() )
				return true;
		break;

			// Commands that return one array of data.
		case 'idle':
			if( $this->sendCommand(strtolower($member),$args) and $this->extractPairs($o) and assert('is_array($o)') )
				return $o;
			break;

			// Commands that return an list of array of data.
		/*case 'playlistinfo':
			$e = strtr(strtolower($member),array( // For each command, define the last key used to seperate the array of data
				'playlistinfo' => 'id'));
			if( ( (!$this->command_sent and $this->sendCommand(strtolower($member)))
					or ($this->command_sent) )
				and $this->extractPairs($o,$e) and assert('is_array($o)') )
				return $o;
			break;*/

		default:
			throw new ProtocolException('Command not implemented (or do not have sufficient privileges (or do not exists)).');
		}
		return false;
	}


	// }}}
	// {{{ doOpen, doClose

	function doOpen()
	{
		if( ! is_resource($this->con) )
		{
			if( ! $this->con = fsockopen($this->host, $this->port, $errno, $errmsg) )
				throw new RuntimeException($errmsg,$errno);
			else
				register_shutdown_function(array($this,'doClose'));
		}

		if( ($this->command_sent=true) and ! $this->untilOK($o) ) throw new ProtocolException;
		if( preg_match('/([\d\.]+)$/', $o, $m) ) $this->server_version = $m[1];
	}

	function doClose()
	{
		if( ! is_resource($this->con) )
		{
			$this->close;
			$this->con = null;
		}
	}

	// }}}
	// {{{ sendCommand, extractPairs, untilOK

	private function sendCommand($cmd, array $args=array())
	{
		assert('$this->con');
		assert('is_string($cmd)');
		assert('is_array($args)');
		assert('$args==array() or array_filter($args,"is_string")');
		array_unshift($args, $cmd);

		assert('!$this->command_sent');
		if( $this->command_sent ) return false;

		if( $this->con )
		{
			$cmd = array_reduce($args,function($a,$b){
				if( strpos($b,' ')!==false ) return trim(sprintf('%s "%s"',$a,str_replace('"','\"',$a)));
				return trim("$a $b");
			},'') . "\n";
			for( $o=0,$l=strlen($cmd); $o<$l; $o+=$w )
				if( false === ($w = fwrite($this->con, substr($cmd, $o))) ) break;
			return ($this->command_sent=true);
		}
		return false;
	}

	/**
	 * Read lines from the stream and extract pairs of data.
	 * Params:
	 *   (out) array $pairs = Will contain the keys/values extract from the readed lines.
	 *   string $group = Stop when a specific keys is found instaed of "OK".
	 * Returns:
	 *   true = Lines read and extracted successfully.
	 */
	private function extractPairs(&$pairs=null, $end='')
	{
		assert('is_string($end)');

		assert('$this->command_sent');
		if( ! $this->command_sent ) return false;
		$pairs = array();

		if( $this->con ) while( ! feof($this->con) )
		{
			$l = trim(fgets($this->con));
			if( substr($l,0,3)=='ACK' ) throw new CommandException($l);
			if( substr($l,0,2)=='OK' ) return !($this->command_sent=false); // OK was found, return true and reset the command.
			if( preg_match('/^([^:]*):[ \t]*(.*)$/',$l,$m) )
			{
				list($k,$v) = array(strtolower($m[1]),$m[2]);
				if( isset($pairs[$k]) and is_array($pairs[$k]) )
					array_push($pairs[$k], $v);
				elseif( isset($pairs[$k]) )
					$pairs[$k] = array($pairs[k], $v);
				else
					$pairs[$k] = $v;
				if( $k==strtolower($end) ) return true; // $end was found, return true, but do not reset the command.
			}
		}
		return false;
	}

	/**
	 * Read lines from the stream until "OK" is found.
	 * Param:
	 *   (out) string $line = Will contain the last line. Propably "OK".
	 * Return:
	 *   true = If "OK" was found.
	 */
	private function untilOK(&$line=null)
	{
		assert('$this->con');

		assert('$this->command_sent');
		if( ! $this->command_sent ) return false;

		if( $this->con ) while( ! feof($this->con) )
		{
			$line = trim(fgets($this->con));
			if( substr($line,0,3)=='ACK' ) throw new CommandException($line);
			if( substr($line,0,2)=='OK' ) return !($this->command_sent=false);
		}
		return false;
	}

	// }}}
}

$m = new Mpd('localhost','6600','');
$m->doOpen();
var_dump( $m->consume(0) );
//while( $r = $m->playlistinfo ) var_dump( $r);

