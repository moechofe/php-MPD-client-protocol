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

		$this->doOpen();

		switch(strtolower($member))
		{

			// Special commands
		case 'close': case 'kill':
			if( $this->sendCommand(strtolower($member)) )
				return true;
			break;

			// Commands that do not return data.
		case 'clearerror': case 'next': case 'play': case 'playid': case 'previous': case 'stop':
		case 'clear': case 'shuffle': case 'ping':
			if( $this->sendCommand(strtolower($member)) and $this->untilOK() )
				return true;
			break;

			// Commands that return one array of data.
		case 'status': case 'currentsong': case 'idle': case 'stats': case 'listall':
		case 'update': case 'rescan':
			if( $this->sendCommand(strtolower($member)) and $this->extractPairs($o) and assert('is_array($o)') )
				return $o;
			break;

			// Commands that return an list of array of data.
		case 'playlistinfo': case 'listplaylists': case 'listallinfo':
			if( ( (!$this->command_sent and $this->sendCommand(strtolower($member)))
					or $this->command_sent )
				and $this->extractPairs($o,true) and assert('is_array($o)') )
				return $o;
			break;

			// Specific processing for lsinfo that remove listing playlists
		case 'lsinfo':
			if( (!$this->command_sent and $this->sendCommand(strtolower($member)))
				or $this->command_sent )
			{
				while( $this->extractPairs($o,true) and assert('is_array($o)')
					and ! $o=array_diff_key($o,array('playlist'=>true,'last-modified'=>true)) )
					if( !$this->command_sent ) break;
				return $o;
			}

		default:
			throw new ProtocolException('Command not implemented (or do not have sufficient privileges (or do not exists)).');
		}
		return false;
	}

	function __call($member, $args)
	{
		assert('is_string($member)');
		assert('is_array($args)');
		assert('array_filter($args,"is_scalar")');
		$args = array_map('strval',$args);

		$this->doOpen();

		switch(strtolower($member))
		{

			// Commands that do not return data.
		case 'consume': case 'crossfade': case 'mixrampdb': case 'mixrampdelay': case 'random':
		case 'repeat': case 'setvol': case 'single': case 'replay_gain_mode': case 'pause': case 'play':
		case 'playid': case 'seek': case 'seekid': case 'seekcur': case 'add': case 'delete':
		case 'deleteid': case 'move': case 'moveid': case 'prio': case 'prioid': case 'shuffle':
		case 'shuffle': case 'swapid': case 'load': case 'playlistadd': case 'playlistclear':
		case 'playlistdelete': case 'playlistmove': case 'rename': case 'rm': case 'save':
			if( $this->sendCommand(strtolower($member),$args) and $this->untilOK() )
				return true;
		break;

			// Commands that return one array of data.
		case 'idle': case 'replay_gain_status': case 'addid': case 'listplaylist': case 'count':
		case 'list': case 'listall': case 'update': case 'rescan':
			if( $this->sendCommand(strtolower($member),$args) and $this->extractPairs($o) and assert('is_array($o)') )
				return $o;
			break;

			// Special processing for stickers commands.
			// XXX: NOT TESTED !
		case 'sticker_get': case 'sticker_set': case 'sticker_delete': case 'sticker_list':
		case 'sticker_find':
			if( $this->sendCommand(strtolower(str_replace($member,array('_'=>' '))),$args) and $this->extractPairs($o) and assert('is_array($o)') )
				return $o;
			break;

			// Commands that return a list of array of data.
		case 'playlistfind': case 'playlistinfo': case 'playlistsearch': case 'plchanges':
		case 'plchangesposid': case 'playlistid': case 'listplaylistinfo': case 'find':
		case 'findadd': case 'listallinfo': case 'search':
			if( ( (!$this->command_sent and $this->sendCommand(strtolower($member),$args))
				or ($this->command_sent) )
			and $this->extractPairs($o,true) and assert('is_array($o)') )
				return $o;
			break;

			// Specific processing for lsinfo that remove listing playlists
		case 'lsinfo':
			if( (!$this->command_sent and $this->sendCommand(strtolower($member),$args))
				or $this->command_sent )
			{
				while( $this->extractPairs($o,true) and assert('is_array($o)')
					and ! $o=array_diff_key($o,array('playlist'=>true,'last-modified'=>true)) )
					if( !$this->command_sent ) break;
				return $o;
			}

		default:
			throw new ProtocolException('Command not implemented (or do not have sufficient privileges (or do not exists)).');
		}
		return false;
	}


	// }}}
	// {{{ doOpen, doClose

	private function doOpen()
	{
		if( ! is_resource($this->con) )
		{
			if( ! $this->con = fsockopen($this->host, $this->port, $errno, $errmsg) )
				throw new RuntimeException($errmsg,$errno);
			else
				register_shutdown_function(array($this,'doClose'));

			if( ($this->command_sent=true) and ! $this->untilOK($o) ) throw new ProtocolException;
			if( preg_match('/([\d\.]+)$/', $o, $m) ) $this->server_version = $m[1];
		}
	}

	private function doClose()
	{
		if( is_resource($this->con) )
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
	 *   bool $group = Enable grouping result. Stop when a specific keys is found instaed of "OK".
	 * Returns:
	 *   true = Lines read and extracted successfully.
	 */
	private function extractPairs(&$pairs=null, $group=false)
	{
		static $old_line = null;

		assert('$this->command_sent');
		if( ! $this->command_sent ) return false;
		$pairs = array();

		$founded_keys = array();
		$group_key = $old_key = '';
		if( $this->con ) while( $old_line or ! feof($this->con) )
		{
			if( $old_line )
			{
				// When grouping data.
				// Restore the previous getted line.
				$line = $old_line;
				$old_line = null;
			}
			else
				$line = trim(fgets($this->con));

			if( substr($line,0,3)=='ACK' ) throw new CommandException($line);
			elseif( preg_match('/^([^:]*):[ \t]*(.*)$/',$line,$m) )
			{
				list($k,$v) = array(strtolower($m[1]),$m[2]);

				if( $group )
				{
					if( ! in_array($k,$founded_keys) ) array_push($founded_keys,$k);
					elseif( $old_key != $k )
					{
						// Grouping the data
						// Cut at this line, and keep it for the next call to this function.
						$old_line = $line;
						return true;
					}
					else
						$old_key = $k;
				}

				if( isset($pairs[$k]) and is_array($pairs[$k]) )
					array_push($pairs[$k], $v);
				elseif( isset($pairs[$k]) )
					$pairs[$k] = array($pairs[$k], $v);
				else
					$pairs[$k] = $v;
			}
			elseif( substr($line,0,2)=='OK' )
			{
				if( $pairs and $group ) { $old_line = $line; return true; } // Return the last group of data.
				return !($this->command_sent=false); // Return true and reset the command.
			}
		}
		return false;
	}

	/**
	 * Read lines from the stream until "OK" is found.
	 * Param:
	 *   (out) string $line = Will contain the last line. Prorbably "OK".
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
var_dump( $m->status );
var_dump( $m->close );
var_dump( $m->status );
//while( $r = $m->lsinfo ) var_dump( $r);

