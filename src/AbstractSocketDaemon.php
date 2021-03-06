<?php
namespace KS;
declare(ticks = 1);

abstract class AbstractSocketDaemon extends AbstractExecutable implements SocketHandlerInterface
{
    private $listeningSocket;
    private $initialized = false;

    private function throwCouldntCreateListener(string $details) : void
    {
        throw new \RuntimeException("Couldn't create a listening socket: '".BaseSocket::getLastGlobalErrorStr()."', details='".$details."'");
    }

    public function run() : void
    {
        $this->init();
        $this->preRun();
        $this->log("Begin listening on socket at '".$this->config->getSocketAddress()."'...", LOG_INFO, [ "syslog", STDOUT ], true);
        try {
            // Set up the socket
            switch ($this->config->getSocketDomain()) {
                case \AF_UNIX:
                    $this->log("Creating unix socket");
                    if (($this->listeningSocket = UnixSocket::createUnixSocket($this->config->getSocketAddress(), $this->config->getSocketType(), $this->config->getSocketProtocol())) === false) {
                        $this->throwCouldntCreateListener("UNIX Socket");
                    }
                    break;
                case \AF_INET:
                    $this->log("Creating inet socket");
                    if (($this->listeningSocket = InetSocket::createInetSocket($this->config->getSocketAddress(), $this->config->getSocketType(), $this->config->getSocketProtocol())) === false) {
                            $this->throwCouldntCreateListener("INET Socket");
                        }
                        break;
                default:
                    $this->throwCouldntCreateListener("Unknown socket type: '".$this->config->getSocketDomain()."'");
                    break;
            }
            if ($this->listeningSocket->setBlocking(false) === Result::FAILED) {
                throw new \RuntimeException("Couldn't make listening socket non-blocking: '".BaseSocket::getLastGlobalStr()."'");
            }
            if ($this->listeningSocket->bind() === Result::FAILED) {
                throw new \RuntimeException("Couldn't bind to socket at {$this->config->getSocketAddress()} ({$this->config->getSocketPort()}): " . $this->listeningSocket->getLastErrorStr());
            }
            if ($this->listeningSocket->listen(5) === Result::FAILED) {
                throw new \RuntimeException("Failed to listen on socket: ".$this->listeningSocket->getLastErrorStr());
            }

            $this->onListen();

            $socketLoop = new SelectSocketLoop();
            $timeout = new TimeDuration(TimeDuration::SECONDS, 1);

            $socketLoop->watchForRead($this->listeningSocket);

            // Establish communication loop
            $shuttingDown = false;
            do {
                $socketsReady = $socketLoop->waitForEvents($timeout);

                if ($socketsReady === Result::FAILED) {
                    throw new \RuntimeException("Error processing socket_select: '".BaseSocket::getLastGlobalErrorStr()."'");
                }
                if (\count($socketsReady) === 0) {
                    continue;
                }

                // Process socket events
                foreach ($socketsReady as $socket) {
                    if ($socket === $this->listeningSocket) { 
                        $acceptedSocket = new BufferedSocket($socket->accept()->getSocketForMove());
                        if ($acceptedSocket === false) {
                            throw new \RuntimeException("Error attempting to accept connection: '".$accepted->getLastErrorStr()."'");
                        }
                        
                        $socketLoop->watchForRead($acceptedSocket);
                        $this->onConnect($acceptedSocket);
                        // Create buffer for connection
                        continue;
                    }

                    // Anything in here is a buffered socket
                    if ($socket->processReadReady() === Result::FAILED) {
                        throw new \RuntimeException("Error reading socket: '".$socket->getLastErrorStr()."'");
                    }
                    if ($socket->isSocketClosed()) {
                        // Socket has closed
                        $socketLoop->unwatchForRead($socket);
                        $socket->close();
                        continue;
                    }
                    $buffer = $socket->getReadBuffer();

                    $termPos = \strpos($buffer, "\0");
                    if ($termPos === false) {
                        continue; // Message not ready
                    }
                    $buffer = \substr($buffer, 0, $termPos+1);
                    $socket->reduceReadBuffer($termPos+1);

                    // Process message
                    try {
                        $this->preProcessMessage($socket, $buffer);
                        $response = $this->processMessage($socket, $buffer);
                        $this->preSendResponse($socket, $response);
                        if ($response) {
                            $socket->writeData($response);
                        }
                        $this->postSendResponse($socket, $response);
                    } catch (Exception\ConnectionClose $e) {
                        $this->preDisconnect($socket);
                        $this->postDisconnect($socket);
                        break;
                    } catch (Exception\Shutdown $e) {
                        $shuttingDown = true;
                        $this->preDisconnect($socket);
                        $this->postDisconnect($socket);
                        break;
                    } catch (Exception\UserMessage $e) {
                        $jsonapi = [
                            'errors' => [
                                [
                                    'status' => $e->getCode(),
                                    'title' => 'Error',
                                    'detail' => $e->getMessage(),
                                ]
                            ]
                        ];
                        $this->preSendResponse($socket, $jsonapi);
                        $jsonapi = json_encode($jsonapi);
                        $socket->write($jsonapi);
                        $this->postSendResponse($socket);
                    }
                }

            } while (!$shuttingDown);
            $this->preShutdown();
            $this->shutdown();
            $this->postShutdown();
        } catch (\Throwable $e) {
            $this->preShutdown();
            $this->shutdown();
            $this->postShutdown();
            throw $e;
        }
    }

    /**
     * To be overridden by child classes
     *
     * Child implementations should always call parent to complete initialization
     */
    protected function init() : void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        $this->log("Daemon Initialized", LOG_INFO, [ "syslog", STDOUT ], true);
    }

    abstract protected function processMessage(?BaseSocket $socket, string $msg) : ?string;

    public function shutdown(): void
    {
        $this->log("Shutting down", LOG_INFO, [ "syslog", STDOUT ], true);
        if ($this->config->getSocketDomain() === AF_UNIX && file_exists($this->config->getSocketAddress())) {
            $this->log("Cleaning up Unix Socket", LOG_INFO);
            \unlink($this->config->getSocketAddress());
        }
    }



    // Hooks

    public function preRun() : void
    {
        // Override
    }

    public function onListen() : void
    {
        $msg = "Listening on {$this->config->getSocketAddress()}";
        if ($p = $this->config->getSocketPort()) {
            $msg .= ":$p";
        }
        $this->log($msg, LOG_INFO);
    }

    public function onConnect(BaseSocket $socket) : void
    {
        $this->log("Connected to peer", LOG_DEBUG);
    }

    public function preProcessMessage(BaseSocket $socket, string $msg) : void
    {
        $this->log("Got a message: $msg", LOG_DEBUG);
    }

    public function preSendResponse(BaseSocket $socket, $msg) : void
    {
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        $this->log("Got a response: $msg", LOG_DEBUG);
    }

    /**
     * @param string|array $response
     * @return void
     */
    public function postSendResponse(BaseSocket $socket, string $response) : void
    {
        $this->log("Response sent.", LOG_DEBUG);
    }

    public function preDisconnect(BaseSocket $socket) : void
    {
        $this->log("Disconnecting from peer", LOG_DEBUG);
    }

    public function postDisconnect() : void
    {
        $this->log("Disconnected. Waiting.", LOG_DEBUG);
    }

    public function preShutdown() : void
    {
        $this->log("Preparing to shutdown.", LOG_DEBUG);
    }

    public function postShutdown() : void
    {
        $this->log("Goodbye.", LOG_DEBUG);
    }
}

