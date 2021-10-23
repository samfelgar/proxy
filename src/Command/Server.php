<?php

namespace Samfelgar\Proxy\Command;

use Clue\React\Socks\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use Samfelgar\Proxy\Console\Command;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Server extends Command
{
    protected static $defaultName = 'server:listen';

    protected function configure(): void
    {
        $this->setDescription('Listen to the specified port and redirect the requests to the specified socket');
        $this->setHelp('Listen to the specified port and redirect the requests to the specified socket');
        $this->setOptions();
    }

    private function setOptions(): void
    {
        $this->addOption(
            'port',
            'p',
            InputOption::VALUE_REQUIRED,
            'The port to listen to',
            8080
        );

        $this->addOption(
            'socket',
            's',
            InputOption::VALUE_REQUIRED,
            'The socket to redirect to'
        );

        $this->addOption(
            'timeout',
            't',
            InputOption::VALUE_OPTIONAL,
            'Request timout',
            60.0
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getOption('port');
        $socket = $input->getOption('socket');
        $timout = $input->getOption('timeout');

        if ($socket === null) {
            $this->output->error('You must inform the socket');
            return BaseCommand::INVALID;
        }

        $output->writeln('Listening to: ' . $port);

        $proxy = $this->proxy($socket);

        $connector = new Connector([
            'tcp' => $proxy,
            'dns' => false,
        ]);

        $browser = new Browser($connector);
        $browser->withTimeout($timout);

        $http = $this->httpServer(function (ServerRequestInterface $request) use ($browser) {
            $uri = $this->normalizeUri($request->getUri());

            $this->output->text([
                'Request method: ' . $request->getMethod(),
                'Request uri: ' . $request->getUri(),
                'Request body: ' . $request->getBody(),
                'Normalized URI: ' . $uri,
            ]);

            $this->output->newLine();

            if ($request->getMethod() === 'CONNECT') {
                return new Response(200, [], '', '1.1', 'Connection established');
            }

            $onFulfilled = function (ResponseInterface $response) {
                $this->output->writeln('success');
                return $response;
            };

            $onRejected = function (\Exception $exception) {
                $this->output->error($exception->getMessage());

                if ($exception instanceof ResponseException) {
                    return $exception->getResponse();
                }

                return new Response($exception->getCode(), [], $exception->getMessage());
            };

            return $browser
                ->request(
                    $request->getMethod(),
                    $uri,
                    $request->getHeaders(),
                    (string) $request->getBody()
                )
                ->then($onFulfilled, $onRejected);
        });

        $socket = $this->socketServer($port);

        $socket->on('connection', function (ConnectionInterface $connection) {
            $this->output->writeln('Connection received: ' . $connection->getLocalAddress());
        });

        $socket->on('error', function (\Exception $exception) {
            $this->output->writeln('Error: ' . $exception->getMessage());
        });

        $http->listen($socket);

        return BaseCommand::SUCCESS;
    }

    private function httpServer(callable $handler): HttpServer
    {
        return new HttpServer($handler);
    }

    private function socketServer(int $port): SocketServer
    {
        $uri = '127.0.0.1:' . $port;
        return new SocketServer($uri);
    }

    private function proxy(int $port): Client
    {
        $uri = "127.0.0.1:$port";

        return new Client($uri, new Connector());
    }

    private function normalizeUri(UriInterface $uri): UriInterface
    {
        if ($uri->getPort() === 443) {
            return $uri
                ->withPort(null)
                ->withScheme('https');
        }

        return $uri;
    }
}