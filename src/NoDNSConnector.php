<?php
namespace PharmaIntelligence\MLLP;
use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise;
use React\Promise\Deferred;
use React\SocketClient\ConnectorInterface;
use React\SocketClient\ConnectionException;

class NoDNSConnector implements ConnectorInterface
{
    private $loop;
    
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }
    public function create($host, $port)
    {
        return $this
            ->resolveHostname($host)
            ->then(function ($address) use ($port, $host) {
                return $this->createSocketForAddress($address, $port, $host);
            });
    }
    public function createSocketForAddress($address, $port, $hostName = null)
    {
        $url = $this->getSocketUrl($address, $port);
        $contextOpts = [];
        if ($hostName !== null) {
            $contextOpts['ssl']['SNI_enabled'] = true;
            $contextOpts['ssl']['SNI_server_name'] = $hostName;
        }
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $context = stream_context_create($contextOpts);
        $socket = stream_socket_client($url, $errno, $errstr, 0, $flags, $context);
        if (!$socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
                $errno
            ));
        }
        stream_set_blocking($socket, 0);
        // wait for connection
        return $this
            ->waitForStreamOnce($socket)
            ->then([$this, 'checkConnectedSocket'])
            ->then([$this, 'handleConnectedSocket']);
    }
    protected function waitForStreamOnce($stream)
    {
        $deferred = new Deferred();
        $loop = $this->loop;
        $this->loop->addWriteStream($stream, function ($stream) use ($loop, $deferred) {
            $loop->removeWriteStream($stream);
            $deferred->resolve($stream);
        });
        return $deferred->promise();
    }
    public function checkConnectedSocket($socket)
    {
        // The following hack looks like the only way to
        // detect connection refused errors with PHP's stream sockets.
        if (false === stream_socket_get_name($socket, true)) {
            return Promise\reject(new ConnectionException('Connection refused'));
        }
        return Promise\resolve($socket);
    }
    public function handleConnectedSocket($socket)
    {
        return new Stream($socket, $this->loop);
    }
    protected function getSocketUrl($host, $port)
    {
        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }
        return sprintf('tcp://%s:%s', $host, $port);
    }
    protected function resolveHostname($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Promise\resolve($host);
        }
        return gethostbyname($host);
    }
}
