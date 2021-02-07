<?php


namespace App\Ssh;


use Ratchet\ConnectionInterface as RatchetConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnectionInterface;

/**
 * Class SshMessageComponent
 * @package App\Ssh
 */
class SshMessageComponent implements MessageComponentInterface {

    /**
     * @var ReactConnectionInterface
     */
    private $stream;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @param LoopInterface $loop
     */
    public function setLoop(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     * @param RatchetConnectionInterface $conn
     */
    public function onOpen(RatchetConnectionInterface $conn)
    {
    }

    /**
     * @param RatchetConnectionInterface $connection
     * @param array $params
     */
    private function connect(RatchetConnectionInterface $connection, array $params)
    {
        $connector = new SshProcessConnector(
            rawurlencode($params['username']) . ':' . rawurlencode($params['pass']) . '@' . $params['ip'],
            $this->loop
        );
        $connector->connect(null)->then(
            function (ReactConnectionInterface $stream) use ($connection) {
                $this->stream = $stream;
                $connection->send(json_encode([
                    'handler' => 'authSuccess',
                    'data' => []
                ]));
                $this->registerEventHandlers($connection);
                $this->loop->addTimer(0.1, function () {
                    $this->stream->write('echo "current pwd $PWD"' . PHP_EOL);
                });
            },
            function ($error) use ($connection) {
                $error->send(json_encode([
                    'handler' => 'authFail',
                    'data' => [
                        'error' => $error
                    ]
                ]));
            }
        );
    }

    /**
     * @param RatchetConnectionInterface $connection
     */
    private function registerEventHandlers(RatchetConnectionInterface $connection)
    {
        foreach (['data', 'error', 'draw', 'end'] as $event) {
            $this->stream->on($event, function ($chunk) use ($connection) {
                $isPWd = false;
                if (mb_strrpos($chunk, 'current pwd') === 0) {
                    $isPWd = true;
                }
                if ($isPWd === true) {
                    $connection->send(json_encode([
                        'handler' => 'loadPwd',
                        'data' => [
                            'pwd' => str_replace('current pwd', false, $chunk)
                        ]
                    ]));
                } else {
                    $connection->send(json_encode([
                        'handler' => 'loadBuffer',
                        'data' => [
                            'buffer' => $chunk
                        ]
                    ]));
                }
            });
        }
    }

    /**
     * @param RatchetConnectionInterface $connection
     * @param $params
     */
    private function write(RatchetConnectionInterface $connection, $params)
    {
        $this->stream->write($params['command'] . PHP_EOL);
        $this->loop->addTimer(0.1, function () {
            $this->stream->write('echo "current pwd $PWD"' . PHP_EOL);
        });
    }

    /**
     * @param RatchetConnectionInterface $from
     * @param MessageInterface $message
     */
    public function onMessage(RatchetConnectionInterface $from, MessageInterface $message)
    {
        $decodeMessage = json_decode((string)$message->getPayload(), true);
        $method = $decodeMessage['method'];
        $this->$method($from, $decodeMessage['params']);
    }

    /**
     * @param RatchetConnectionInterface $conn
     */
    public function onClose(RatchetConnectionInterface $conn)
    {
        $this->closeStream();
    }

    /**
     *
     */
    private function closeStream()
    {
        if ($this->stream !== null) {
            $this->stream->close();
            $this->stream = null;
        }
    }

    /**
     * @param RatchetConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(RatchetConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
        $this->closeStream();
    }
}