<?php


namespace App\Ssh;

use Clue\React\SshProxy\Io\CompositeConnection;
use Clue\React\SshProxy\Io\LineSeparatedReader;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\ConnectorInterface;
use Clue\React\SshProxy\Io;

/**
 * Class SshProcessConnector
 * @package App\Ssh
 */
class SshProcessConnector implements ConnectorInterface
{
    /**
     * @var string
     */
    private $cmd;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var bool
     */
    private $debug = true;

    /**
     *
     * [ssh://][user[:pass]@]host[:port]
     *
     * You're highly recommended to use public keys instead of passing a
     * password here. If you really need to pass a password, please be aware
     * that this will be passed as a command line argument to `sshpass`
     * (which may need to be installed) and this password may leak to the
     * process list if other users have access to your system.
     *
     * @param string $uri
     * @param LoopInterface $loop
     * @throws \InvalidArgumentException
     */
    public function __construct(string $uri, LoopInterface $loop)
    {
        // URI must use optional ssh:// scheme, must contain host and neither pass nor target must start with dash
        $parts = \parse_url((\strpos($uri, '://') === false ? 'ssh://' : '') . $uri);
        $pass = isset($parts['pass']) ? \rawurldecode($parts['pass']) : null;
        $target = (isset($parts['user']) ? \rawurldecode($parts['user']) . '@' : '') . (isset($parts['host']) ? $parts['host'] : '');
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'ssh' || (isset($pass[0]) && $pass[0] === '-') || $target[0] === '-') {
            throw new \InvalidArgumentException('Invalid SSH server URI');
        }

        $this->cmd = 'exec ';
        if ($pass !== null) {
            $this->cmd .= 'sshpass -p ' . \escapeshellarg($pass) . ' ';
        }
        $this->cmd .= 'ssh -vv ';

        // disable interactive password prompt if no password was given (see sshpass above)
        if ($pass === null) {
            $this->cmd .= '-o BatchMode=yes ';
        }

        if (isset($parts['port']) && $parts['port'] !== 22) {
            $this->cmd .= '-p ' . $parts['port'] . ' ';
        }
        $this->cmd .= \escapeshellarg($target);
        $this->loop = $loop;
    }

    /**
     * @param null $uri
     * @return \React\Promise\Promise|\React\Promise\PromiseInterface
     */
    public function connect($uri = null)
    {
        $command = $this->cmd;
        $process = Io\processWithoutFds($command);
        $process->start($this->loop);

        if ($this->debug) {
            echo 'Launched "' . $command . '" with PID ' . $process->getPid() . PHP_EOL; // @codeCoverageIgnore
        }

        $deferred = new Deferred(function () use ($process) {
            $process->stdin->close();
            $process->terminate();

            throw new \RuntimeException('Connection cancelled while waiting for SSH client');
        });

        // pause STDOUT and process STDERR one line at a time until connection is ready
        $process->stdout->pause();
        $last = null;
        $connected = false;
        $debug = $this->debug;
        $stderr = new LineSeparatedReader($process->stderr);
        $stderr->on('data', function ($line) use ($deferred, $process, &$last, $debug, &$connected) {
            // remember last line for error output in case process exits
            $last = $line;
            if ($debug) {
                echo \addcslashes($line, "\0..\032") . PHP_EOL; // @codeCoverageIgnore
            }
            if (mb_strpos($line, 'debug') !== false) {
                return;
            }
            $process->stdout->emit('data', [$line]);

            // channel is ready, so resume STDOUT stream and resolve connection
            $process->stdout->resume();
            $connection = new CompositeConnection($process->stdout, $process->stdin);
            $deferred->resolve($connection);
            $connected = true;
        });

        // If STDERR closes before connection was established, explicitly close STDOUT stream.
        // The STDOUT stream starts in a paused state and as such will prevent the process exit
        // logic from triggering when it is not resumed.
        $stderr->on('close', function () use ($process, &$connected) {
            if (!$connected) {
                $process->stdout->close();
            }
        });

        $process->on('exit', function ($code) use ($deferred, &$last, $debug) {
            if ($debug) {
                echo 'Process exit with code ' . $code . PHP_EOL; // @codeCoverageIgnore
            }
            $deferred->reject(new \RuntimeException(
                'SSH client died (' . $last . ')',
                $code
            ));
        });

        return $deferred->promise();
    }
}