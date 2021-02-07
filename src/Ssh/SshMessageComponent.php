<?php


namespace App\Ssh;


use Ratchet\ConnectionInterface as RatchetConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnectionInterface;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use SensioLabs\AnsiConverter\Theme\SolarizedTheme;

/**
 * Class SshMessageComponent
 * @package App\Ssh
 */
class SshMessageComponent implements MessageComponentInterface
{

    /**
     * @var ReactConnectionInterface
     */
    private $stream;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var AnsiToHtmlConverter
     */
    private $ansiConverter;

    /**
     * SshMessageComponent constructor.
     * @param AnsiToHtmlConverter $ansiConverter
     */
    public function __construct(AnsiToHtmlConverter $ansiConverter)
    {
        $this->ansiConverter = $ansiConverter;
    }

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
            $this->loop,
            $params['debug']
        );
        $connector->connect(null)->then(
            function (ReactConnectionInterface $stream) use ($connection) {
                $this->stream = $stream;
                $connection->send(json_encode([
                    'handler' => 'authSuccess',
                    'data' => []
                ]));
                $this->registerEventHandlers($connection);
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
            $this->stream->on($event, function ($chunk = null) use ($connection, $event) {
                if ($chunk !== null) {
                    $matches = [];
                    preg_match("/[A-Za-z0-9-_]*@[A-Za-z0-9-_]*:[\/A-Za-z~_-]*[\s][~A-Za-z\/_-]*/", $chunk, $matches);
                    $connectionInfo = $matches[0] ?? null;
                    if ($connectionInfo !== null && ($pos = strrpos($chunk, "]0;$connectionInfo")) !== false) {
                        $chunk = trim(substr($chunk, 0, $pos));
                    }
                    $connection->send(json_encode([
                        'handler' => 'loadBuffer',
                        'event' => $event,
                        'data' => [
                            'buffer' => $this->ansiConverter->convert($chunk),
                            'connectionInfo' => $connectionInfo
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