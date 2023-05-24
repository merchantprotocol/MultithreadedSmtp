<?php

namespace Merchantprotocol\MultithreadedSmtp;

use Swift_Transport;
use Swift_Mime_Message;
use Swift_Events_EventListener;
use Swift_Mime_SimpleMessage;
use Swift_Transport_IoBuffer;
use Swift_Events_SimpleEventDispatcher;
use Swift_Transport_EsmtpTransport;
use Swift_AddressEncoder_IdnAddressEncoder;

use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\SecureConnector;
use React\Socket\ConnectionInterface;
use React\Socket\StreamEncryption;
use React\Promise\Promise;

use Merchantprotocol\MultithreadedSmtp\Conversation\Hello;

/**
 * Custom implementation of Swift_Transport using ReactPHP for asynchronous operations.
 *
 * This transport class allows sending emails using SMTP with ReactPHP as the underlying event loop.
 * It provides additional features such as registering response callbacks and handling SMTP responses asynchronously.
 *
 * Example usage:
 * ```php
 * $transport = new SwiftTransportReact([
 *     'host' => 'smtp.example.com',
 *     'port' => 587,
 *     'encryption' => 'tls',
 *     'username' => 'user@example.com',
 *     'password' => 'password',
 * ]);
 *
 * // Register a callback for response code '250'
 * $transport->onResponse('250', function ($response) {
 *     echo "Received response: $response";
 * });
 *
 * // Register another callback for response code '251'
 * $transport->onResponse('251', function ($response) {
 *     echo "Received response: $response";
 * });
 *
 * // Create the Swift Mailer instance with the custom transport
 * $mailer = new Swift_Mailer($transport);
 *
 * // Create a message
 * $message = (new Swift_Message('Test Subject'))
 *     ->setFrom(['sender@example.com' => 'Sender'])
 *     ->setTo(['recipient@example.com' => 'Recipient'])
 *     ->setBody('Hello, World!');
 *
 * // Send the message
 * $result = $mailer->send($message);
 *
 * echo "Sent $result emails";
 * ```
CLIENT: EHLO example.com
SERVER: 250-mail.example.com
        250-PIPELINING
        250-SIZE 10240000
        250-STARTTLS
        250-AUTH LOGIN PLAIN
        250-ENHANCEDSTATUSCODES
        250-8BITMIME
        250-DSN
        250 SMTPUTF8
CLIENT: STARTTLS
SERVER: 220 Ready to start TLS
... (TLS handshake takes place)
CLIENT: EHLO example.com
SERVER: 250-mail.example.com
        250-PIPELINING
        250-SIZE 10240000
        250-AUTH LOGIN PLAIN
        250-ENHANCEDSTATUSCODES
        250-8BITMIME
        250-DSN
        250 SMTPUTF8
CLIENT: AUTH LOGIN
SERVER: 334 VXNlcm5hbWU6
CLIENT: BASE64_ENCODED_USERNAME
SERVER: 334 UGFzc3dvcmQ6
CLIENT: BASE64_ENCODED_PASSWORD
SERVER: 235 2.7.0 Authentication successful
CLIENT: MAIL FROM:<sender@example.com>
SERVER: 250 2.1.0 Sender OK
CLIENT: RCPT TO:<recipient@example.com>
SERVER: 250 2.1.5 Recipient OK
CLIENT: DATA
SERVER: 354 Start mail input; end with <CRLF>.<CRLF>
CLIENT: From: Sender <sender@example.com>
CLIENT: To: Recipient <recipient@example.com>
CLIENT: Subject: Test Email
CLIENT:
CLIENT: This is the body of the email.
CLIENT:
CLIENT: .
SERVER: 250 2.0.0 OK: queued as ABC123
CLIENT: QUIT
SERVER: 221 2.0.0 Bye

 *
 * @package Acelle\Library
 */
class ReactSwiftTransport extends Swift_Transport_EsmtpTransport implements Swift_Transport
{
    /**
     * ESMTP extension handlers.
     *
     * @var Swift_Transport_EsmtpHandler[]
     */
    private $handlers = [];

    /**
     * ESMTP capabilities.
     *
     * @var string[]
     */
    private $capabilities = [
        'STARTTLS' => false,
        'PIPELINING' => false,
    ];

    protected $loop;
    protected $connector;
    protected $connection = false;
    protected $started = false;
    protected $eventDispatcher;
    protected $tlsStarted = false;
    protected $context;

    protected $buffer = '';
    protected $responses = [];
    protected $messages = [];
    protected $currentMessage;
    private $responseCallbacks = [];
    private $doneCallbacks = [];

    private $debug = true;
    private $lastDebugMessage;

    private $helloSent = false;
    private $startTlsSent = false;
    private $streamEncryption;

    private $params = [
        'username' => null,
        'password' => null,
        'host' => 'localhost',
        'encryption' => 'tls',
        'port' => 25,
        'protocol' => 'tcp',
        'timeout' => 30,
        'blocking' => 1,
        'tls' => false,
        'type' => Swift_Transport_IoBuffer::TYPE_SOCKET,
        'stream_context_options' => [],
        'espProvider' => false
    ];

    public function __construct($params = [], React\EventLoop\LoopInterface $loop = null, array $extensionHandlers = [], Swift_Events_EventDispatcher $eventDispatcher = null, $localDomain = '127.0.0.1', Swift_AddressEncoder $addressEncoder = null)
    {
        $this->params = array_merge($this->params, $params);
        // for swift
        $this->eventDispatcher = $eventDispatcher ?: new Swift_Events_SimpleEventDispatcher();
        $this->setExtensionHandlers($extensionHandlers);
        $this->addressEncoder = $addressEncoder ?? new Swift_AddressEncoder_IdnAddressEncoder();
        $this-> domain = $localDomain;
        // for reactphp/securesocket
        $this->context = [
            'tls' => [
                'verify_peer' => false, // Set to true to enable peer verification
                'verify_peer_name' => false, // Set to true to enable peer name verification
                'allow_self_signed' => false, // Set to true to allow self-signed certificates
                // 'disable_compression' => true, // Disable SSL/TLS compression
                // 'crypto_method' => STREAM_CRYPTO_METHOD_ANY_CLIENT, // Specify the desired TLS version
            ]
        ];
        // for reactphp/socket
        $this->loop = $loop ?: Factory::create();
        $this->connector = new Connector($this->context, $this->loop);

        // added this from secureConnector.php
        $this->streamEncryption = new StreamEncryption($this->loop, false);
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        // // 2. Form the command to send the email
        // $command = $this->formEmailCommand($from, $to, $subject, $body);
        $this->addMessage($message);

        // Start conversation
        // $this->start(); // is blocked until done
        $this->loop->run();
        // done blocked conversation

        // 5. Check the response and return accordingly
        // if ($this->isSuccessResponse($response)) {
        //     return count($to); // return the number of recipients
        // } else {
        //     return false;
        // }
    }

    /**
     * Triggered when the SERVER is done responding to a response code
     *
     * @param [string] $completedResponseCode
     * @return void
     */
    // protected function conversation( $completedResponseCode, $responseMessage )
    // {
    //     $this->debug( $completedResponseCode.' done ', 'yellow');
    //     switch ($completedResponseCode) {
    //         case '220':
    //             if (!$this->helloSent) {
    //                 $this->helloSent = true;
    //                 return $this->sendCommand(sprintf("EHLO %s\r\n", $this->domain));
    //             }
    //             // second pass of 220 is STARTTLS 200 Go Ahead

    //             break;

    //         case '250':
    //             // we want to start a TLS and the client does too, but we haven't yet
    //             if (!$this->tlsStarted && $this->params['encryption']=='tls' && $this->capabilities['STARTTLS']){
    //                 try {
    //                     $this->startTLS();
    //                     // $this->debug("TLS handshake successful. Connection upgraded to secure TLS.");
    //                 } catch (\Exception $e) {
    //                     $this->debug("Failed to establish TLS connection: " . $e->getMessage(), 'yellow');
    //                     // Handle TLS connection failure accordingly
    //                 }
    //                 return;
    //             }

    //             // tls is done, now login
    //             $this->sendCommand("AUTH LOGIN\r\n");
    //             break;

    //         case '334':
    //             switch ($responseMessage) {
    //                 case 'VXNlcm5hbWU6':
    //                 $this->sendCommand(sprintf("%s\r\n", base64_encode($this->params['username'])));
    //                 break;
    //                 case 'UGFzc3dvcmQ6':
    //                 $this->sendCommand(sprintf("%s\r\n", base64_encode($this->params['password'])));
    //                 break;
    //             }
    //             break;

    //         case '235':
    //             $message = $this->getCurrentMessage();

    //             $from = $message->getFrom();
    //             $this->doMailFromCommand($from);
    //             break;

    //     }
    // }

    // public function onConnection() {

    //     // Prepare all of the messages we're going to send
    //     $conversations = [];
    //     foreach ($messages as $message) {
    //         $conversations[] = [
    //             $this->doMailFromCommand($message->getFrom()),
    //             $this->doRcptToCommand($message->getFrom()),
    //             $this->doMailBodyCommand($message),
    //         ];
    //     }

    //     $this->clientHello()
    //         ->then(function () {
    //             return $this->onLogin();
    //         })
    //         ->then(function () use ($conversations) {
    //             return $this->sendConversations($conversations);
    //         })
    //         ->then(function () {
    //             return $this->sendCommand("QUIT\r\n");
    //         })
    //         ->catch(function ($error) {
    //         // Handle any errors that occurred during the conversation
    //         // Log the error, rollback any changes, or perform any necessary cleanup
    //         });

    //     // login and then send all of the emails before we logout and close the connection
    //     $this->sendCommands([
    //         sprintf("EHLO %s\r\n", $this->domain) => $this->onHello,
    //         sprintf("AUTH LOGIN %s\r\n", $this->domain) => $this->onLogin,
    //         $conversations,
    //         sprintf("QUIT\r\n") => NULL,
    //     ]);
    // }

    public function start()
    {
        if (!$this->started) {
            $evt = $this->eventDispatcher->createTransportChangeEvent($this);

            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            // prepare for the responses
            $this->onResponse('220', [$this, 'parseHelloResponse']);
            $this->onDone('220', [$this, 'doHelloConversation']);

            // start the connection
            $this->connector->connect($this->params['host'].':'.$this->params['port'])->then(
                function (ConnectionInterface $smtp_conn) use ($evt) {

                    $this->connection = $smtp_conn;
                    $this->started = true;

                    // Log the connection attempt
                    $this->debug('Connected to ' . $this->params['host'] . ':' . $this->params['port'], 'yellow');

                    try {
                        if ($evt) {
                            $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
                        }
                    } catch (Swift_TransportException $e) {
                        // Log the exception
                        $this->debug('Error establishing socket connection: ' . $e->getMessage(), 'yellow');
                    }

                    // Do the necessary encryption of the connection
                    if ($this->params['encryption'] == 'ssl') {
                        $this->startSSL();
                    }

                    $smtp_conn->on('data', function ($chunk) {
                        // Add the chunk to the buffer
                        $this->buffer .= $chunk;

                        // Check if the buffer contains a complete SMTP response
                        while (($pos = strpos($this->buffer, "\r\n")) !== false) {
                            // Extract the SMTP response from the buffer
                            $response = substr($this->buffer, 0, $pos);
                            $this->saveResponse($response);

                            // Remove the SMTP response from the buffer
                            $this->buffer = substr($this->buffer, $pos + 2);
                        }
                    });

                    $smtp_conn->on('error', function(Exception $e) {
                        $this->debug('Connection error: ' . $e->getMessage(), 'yellow');
                    });

                    $smtp_conn->on('close', function() {
                        $this->debug('Connection closed', 'yellow');
                    });
                },
                function (Exception $e) {
                    // Log the exception
                    $this->debug('Error establishing socket connection: ' . $e->getMessage());
                }
            );
        }
    }

    public function startSSL()
    {
        echo 'Attempting TLS' . PHP_EOL;
        
        // Make sure TLS has not already been started
        if (!$this->tlsStarted) {
            $this->tlsStarted = true;
            // $this->sendCommand("STARTTLS\r\n");

            // set required SSL/TLS context options
            foreach ($this->context as $name => $value) {
                \stream_context_set_option($this->connection->stream, 'ssl', $name, $value);
            }

            $this->streamEncryption->enable($this->connection)->then(
                function (ConnectionInterface $endpointConn) {

                    $this->connection = $endpointConn;
                    echo 'Connect via TLS: '. $endpointConn->getRemoteAddress() . PHP_EOL;

                    $endpointConn->on('error', function(Exception $e) {
                        echo 'Connection error: ' . $e->getMessage() . PHP_EOL;
                    });
                    $endpointConn->on('close', function() {
                        echo 'Connection closed' . PHP_EOL;
                    });

                },
                function (Exception $e) {
                    echo 'Failed to enable TLS: ' . $e->getMessage() . PHP_EOL;
                }
            );
        }
    }

    public function parseHelloResponse($responseCode, $responseMessage)
    {
        $this->clearResponseCallback('220', [$this, 'parseHelloResponse']);

        // determine what provider we're dealing with
        if (!$this->params['espProvider']) {
            // Extract the domain
            $domain = '';
            $ehloPattern = '/^220.*\bEHLO\s+(\S+)\b.*$/i';
            if (preg_match($ehloPattern, $responseMessage, $matches)) {
                $domain = $matches[1];
            }
            $this->params['espProvider'] = $this->determineESP($domain, $responseMessage);
        }
    }

    public function saveResponse( $response )
    {
        $this->debug("SERVER: $response");

        // Process the SMTP response
        $responseCode = substr($response, 0, 3);
        $isDoneResponse = substr($response, 3, 1);
        $responseMessage = substr($response, 4);

        $this->responses[$responseCode] = $responseMessage;

        // Trigger the registered callbacks for the response code
        if (isset($this->responseCallbacks[$responseCode])) {
            foreach ($this->responseCallbacks[$responseCode] as $callback) {
                $callback($responseCode, $responseMessage);
            }
        }

        if ($isDoneResponse === ' ') {
            // Trigger the DONE callbacks for the response code
            if (isset($this->doneCallbacks[$responseCode])) {
                foreach ($this->doneCallbacks[$responseCode] as $callback) {
                    $callback($responseCode, $responseMessage);
                }
            }
        }
    }

    protected function readResponse()
    {
        // get last responses item
        $lastResponseCode = array_key_last($this->responses);
        return $lastResponseCode;
    }

    public function isStarted()
    {
        return $this->started;
    }

    public function stop()
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
            $this->started = false;
        }

        // Stop the ReactPHP loop.
        $this->loop->stop();
    }

    /**
     * Sends a command to the server and checks the return code.
     *
     * @param string $command The command to send
     */
    protected function sendCommand($command)
    {
        $this->debug('CLIENT: '.$command);
        return new Promise(function ($resolve, $reject) use ($command) {
            try {
                $this->connection->write($command);
                $resolve();
            } catch (Exception $e) {
                $reject();
            }
        });
    }

    protected function isSuccessResponse($response)
    {
        // The actual check will depend on how the server indicates success
        return substr($response, 0, 3) == '250';
    }

    protected function extractFailedRecipients($response)
    {
        // The actual extraction will depend on how the server indicates failure
        $failedRecipients = [];
        // parse the response and add the failed recipients to the array
        return $failedRecipients;
    }

    public function onResponse($responseCode, callable $callback)
    {
        $this->responseCallbacks[$responseCode][] = $callback;
    }
    public function clearResponseCallback($responseCode, callable $callbackToRemove)
    {
        if (isset($this->responseCallbacks[$responseCode])) {
            $this->responseCallbacks[$responseCode] = array_filter(
                $this->responseCallbacks[$responseCode],
                function ($callback) use ($callbackToRemove) {
                    return $callback !== $callbackToRemove;
                }
            );
        }
    }
    public function onDone($responseCode, callable $callback)
    {
        $this->doneCallbacks[$responseCode][] = $callback;
    }
    public function clearDoneCallback($responseCode, callable $callbackToRemove)
    {
        if (isset($this->doneCallbacks[$responseCode])) {
            $this->doneCallbacks[$responseCode] = array_filter(
                $this->doneCallbacks[$responseCode],
                function ($callback) use ($callbackToRemove) {
                    return $callback !== $callbackToRemove;
                }
            );
        }
    }
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }
    public function setUsername($username)
    {
        $this->params['username'] = $username;
    }
    public function setPassword($password)
    {
        $this->params['password'] = $password;
    }
    public function setHost($host)
    {
        $this->params['host'] = $host;
    }
    public function setEncryption($encryption)
    {
        $this->params['encryption'] = trim(strtolower($encryption));
    }
    public function setPort($port)
    {
        $this->params['port'] = $port;
    }
    public function setProxy($proxy)
    {
        $this->params['proxy'] = $proxy;
    }
    protected function getBufferParams()
    {
        return $this->params;
    }
    public function getParams()
    {
        return $this->params;
    }
    public function getPassword()
    {
        return $this->params['password'];
    }
    public function getUsername()
    {
        return $this->params['username'];
    }

    protected function addMessage( $message )
    {
        $this->messages[] = $message;
    }
    protected function hasMessages()
    {
        return !empty($this->currentMessage);
    }
    protected function getNextMessage()
    {
        $this->currentMessage = array_pop($this->messages);
        return $this->currentMessage;
    }
    protected function getCurrentMessage()
    {
        if (is_null($this->currentMessage)) {
            return $this->getNextMessage();
        }
        return $this->currentMessage;
    }
    protected function clearCurrentMessage()
    {
        $this->currentMessage = null;
    }

    /**
     * Set ESMTP extension handlers.
     *
     * @param Swift_Transport_EsmtpHandler[] $handlers
     *
     * @return $this
     */
    public function setExtensionHandlers(array $handlers)
    {
        $assoc = [];
        foreach ($handlers as $handler) {
            $assoc[$handler->getHandledKeyword()] = $handler;
        }
        uasort($assoc, function ($a, $b) {
            return $a->getPriorityOver($b->getHandledKeyword());
        });
        $this->handlers = $assoc;
        $this->setHandlerParams();

        return $this;
    }

    /**
     * Get ESMTP extension handlers.
     *
     * @return Swift_Transport_EsmtpHandler[]
     */
    public function getExtensionHandlers()
    {
        return array_values($this->handlers);
    }

    /** Determine ESMTP capabilities by function group */
    private function getCapabilities($ehloResponse)
    {
        $capabilities = [];
        $ehloResponse = trim($ehloResponse ?? '');
        $lines = explode("\r\n", $ehloResponse);
        array_shift($lines);
        foreach ($lines as $line) {
            if (preg_match('/^[0-9]{3}[ -]([A-Z0-9-]+)((?:[ =].*)?)$/Di', $line, $matches)) {
                $keyword = strtoupper($matches[1]);
                $paramStr = strtoupper(ltrim($matches[2], ' ='));
                $params = !empty($paramStr) ? explode(' ', $paramStr) : [];
                $capabilities[$keyword] = $params;
            }
        }

        return $capabilities;
    }

    /** Set parameters which are used by each extension handler */
    private function setHandlerParams()
    {
        foreach ($this->handlers as $keyword => $handler) {
            if (\array_key_exists($keyword, $this->capabilities)) {
                $handler->setKeywordParams($this->capabilities[$keyword]);
            }
        }
    }

    /** Get ESMTP handlers which are currently ok to use */
    private function getActiveHandlers()
    {
        $handlers = [];
        foreach ($this->handlers as $keyword => $handler) {
            if (\array_key_exists($keyword, $this->capabilities)) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

    public function ping()
    {
        if (!$this->connection) {
            return false;
        }

        try {
            $this->connection->write('');

            return true;
        } catch (\Exception $e) {
            // If an exception was thrown when we tried to write to the connection,
            // we consider the connection as not alive.
            // @TODO throw an exeption here
            return false;
        }
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    private function debug($message, $color = 'default')
    {
        // convert exceptions to string message
        $e = false;
        if ($message instanceof Exception) {
            $e = $message;
            $message = $e->getMessage().' on line '.$e->getLine().' in '.$e->getFile();
        }
        // dont allow duplicates
        if ($this->lastDebugMessage == $message) {
            return false;
        }
        $this->lastDebugMessage = $message;

        if ($this->debug) {
            if (function_exists('config') && config('app.debug')) {
                // Assuming that you're using Laravel's logging system
                \Log::debug($message);
            }
            if ($e) {
                throw new \Exception($message);
            } else {
                if ($color == 'yellow') {
                    echo "\033[33m".rtrim($message, PHP_EOL)."\033[0m". PHP_EOL;
                } else {
                    echo rtrim($message, PHP_EOL). PHP_EOL;
                }
            }
        }
    }

    function determineESP($domain, $responseMessage)
    {
        $espProviders = config('esp.providers');

        // Try to match based on domain
        if (array_key_exists($domain, $espProviders)) {
            return $espProviders[$domain];
        }

        $smtpStatements = config('esp.smtp_statements');  
        foreach ($smtpStatements as $statement => $provider) {
            if (strpos($responseMessage, $statement) !== false) {
                $responseMessage = $statement;
                break;
            }
        }

        if ($responseMessage !== null) {
            // SMTP statement was found in the 
            // Try to match based on SMTP statement
            foreach ($smtpStatements as $statement => $provider) {
                if (strpos($responseMessage, $statement) !== false) {
                    return $provider;
                }
            }
        }

        // If no match found
        return $responseMessage;
    }
}