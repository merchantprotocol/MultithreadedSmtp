<?php

namespace Merchantprotocol\MultithreadedSmtp;


 /*
 * @package Acelle\Library
 */
class Conversation
{
    /**
     * @var SwiftMailer_Message
     */
    protected $message;

    /**
     * @var ReactSwiftTransport
     */
    protected $transport;

    /**
     * 
     *
     * @var [type]
     */
    protected $capabilities;

    protected $isStarted = false;
    protected $isdone = false;

    /**
     * Accepts the parent objects
     *
     * @param [type] $connection
     */
    public function __construct($transport, $message = null)
    {
        $this->message = $message;
        $this->transport = $transport;
        $this->capabilities = $transport->capabilities;
    }

    public function isStarted()
    {
        return $this->isStarted;
    }

    public function isDone()
    {
        return $this->isDone;
    }
}