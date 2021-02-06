<?php


namespace App\Command;

use App\Ssh\SshMessageComponent;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SshServerCommand
 * @package App\Command
 */
class SshServerCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'app:ssh-server';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $messageComponent = new SshMessageComponent();
        $wsServer = new WsServer($messageComponent);
        $server = IoServer::factory(
            new HttpServer(
                $wsServer
            ),
            8081
        );
        $messageComponent->setLoop($server->loop);
        $wsServer->enableKeepAlive($server->loop);
        $server->run();
        return 0;
    }
}