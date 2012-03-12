<?
namespace WebSocket;

class Server
{
	protected
		$clients,
		$sockets;


	public function __construct($host, $port)
	{
		$this->clients = array();

		$master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

		socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($master, $host, $port);
		socket_listen($master, 20);

		$this->sockets = array($master);

		for(;;)
		{
			echo "Iteration!\n";

			$write = $except = null;

			$socketsChanged = $this->sockets;

			socket_select($socketsChanged, $write, $except, null);

			foreach($socketsChanged as $socket)
			{
				if($socket == $master)
				{
					$newSocket = socket_accept($master);

					if( $newSocket >= 0 )
					{
						echo "New Connection!\n";
						$this->addConnection($newSocket);
					}
					else
						echo "SOCKET BOUNCE!\n";
				}
				else
				{
					$bytes = socket_recv($socket, $buffer, 2048, 0);

					if( $bytes == 0 )
					{
						$this->dropConnection($socket);
					}
					else
					{
						if( $Client = $this->getClient($socket) )
						{
							if( preg_match('/(SAY):(.*)/', $buffer, $match) )
							{
								switch($match[1])
								{
									case 'SAY':
										$this->broadcast(sprintf('%s: %s', $Client->name, trim($match[2])));
									break;
								}
							}
							else
							{
								switch(trim($buffer))
								{
									case 'status':
										print_r($this);
									break;

									default:
										$Client->receive($buffer);
									break;
								}
							}
						}
					}
				}
			}

			#usleep(100000);
		}
	}

	public function __destruct()
	{
		foreach($this->sockets as $socket)
			$this->dropConnection($socket);
	}


	public function addConnection($socket)
	{
		$this->sockets[] = $socket;

		$socket = end($this->sockets);
		$socketID = key($this->sockets); ### Gets clientID from socket auto index

		$Client = new Client($socket);
		$Client->socketID = $socketID;

		$this->clients[$socketID] = $Client;

		return $this;
	}

	public function broadcast($msg)
	{
		foreach($this->clients as $Client)
			$Client->respond($msg);
	}

	public function dropConnection($socket)
	{
		if( $Client = $this->getClient($socket) )
		{
			socket_close($Client->socket);

			unset($this->clients[$Client->socketID]);
			unset($this->sockets[$Client->socketID]);

			echo $Client, " Disconnected\n";

			unset($Client);
		}

		return $this;
	}

	public function getClient($socket)
	{
		foreach($this->clients as $Client)
			if( $Client->socket == $socket) return $Client;

		return false;
	}
}

class Client
{
	public function __construct($socket)
	{
		$this->id = uniqid();
		$this->socket = $socket;
		$this->name = 'Anonymous';
	}

	public function __destruct()
	{
		printf("%s died\n", $this);
	}

	public function __toString()
	{
		return sprintf('User[%s]', $this->name);
	}


	public function receive($data)
	{
		#$message = substr($message, 1, strlen($message) - 2);
		$data = trim($data);

		echo "Received Data: ", $data, "\n";

		$answer = null;

		if( preg_match('/SET ([A-Z]+):(.*)/', $data, $match) )
		{
			switch($match[1])
			{
				case 'NAME':
					$this->name = trim($match[2]);
					$answer = sprintf("Name set to: %s", $this->name);
				break;
			}
		}
		else
		{
			switch($data)
			{
				case "ping":
					$answer = "pong";
				break;

				case "whoami":
					$answer = print_r($this, true);
				break;

				default:
					$answer = "Invalid Command";
			}
		}

		$this->respond($answer);
	}

	public function respond($data)
	{
		$data = chr(0) . $data . "\n" . chr(255);
		echo "Response Data: ", $data;
		socket_write($this->socket, $data, strlen($data));
	}
}




error_reporting(E_ALL);

$errorHandler = function($errno, $errstr, $errfile, $errline)
{
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
};

set_error_handler($errorHandler);

ob_implicit_flush(true);

$host = '192.168.0.5';
$port = 8000;

try
{
	$Server = new Server($host, $port);
}
catch(\Exception $e)
{
	printf("Socket Server Failed: %s, Line %u\n", $e->getMessage(), $e->getLine());
	die(1);
}



function getHeaders($buffer)
{
	$r = $h = $o = null;

	if(preg_match('/GET (.*) HTTP/'		,$buffer, $match)) $r = $match[1];
	if(preg_match("/Host: (.*)\r\n/"	,$buffer, $match)) $h = $match[1];
	if(preg_match("/Origin: (.*)\r\n/"	,$buffer, $match)) $o = $match[1];

	return array($r,$h,$o);
}

function handshake($User, $buffer)
{
	echo "Requesting handshake: ", $buffer, "\n";

	list($resource, $host, $origin) = getheaders($buffer);

	$upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n".
			"Upgrade: WebSocket\r\n".
			"Connection: Upgrade\r\n".
			'WebSocket-Origin: '.$origin."\r\n".
			'WebSocket-Location: ws://'.$host.$resource."\r\n\r\n\0";

	socket_write($User->socket, $upgrade, strlen($upgrade));
	$User->handshake = true;

	echo "Done handshaking: ", $upgrade, "\n";

	return true;
}