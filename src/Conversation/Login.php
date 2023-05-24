<?php

namespace Merchantprotocol\MultithreadedSmtp\Conversation;

use Merchantprotocol\MultithreadedSmtp\Conversation;

 /*
 * @package Acelle\Library
 */
class Login extends Conversation
{
    CONST METHOD_LOGIN = 'LOGIN';

    /**
     * Returns the login method that we're going to use, which is also supported by the server
     *
     * @TODO We'll need to loop through the server capabilities and find the login methods we're compatible with
     *
     * @return void
     */
    public function getAuthMethod()
    {
        return self::METHOD_LOGIN;
    }

    public function doAuthCommand()
    {
         $this->transport->sendCommand("AUTH %s\r\n", $this->getAuthMethod());
    }

    /**
     * processes the username based on the auth method requirements
     *
     * @return string
     */
    public function getUsername()
    {
        switch ($this->getAuthMethod())
        {
            case self::METHOD_LOGIN:
                return base64_encode( $this->transport->getUsername() );
                break;
        }
    }

    public function doUsernameCommand()
    {
         $this->transport->sendCommand(sprintf("%s\r\n", $this->getUsername() ));
    }

    /**
     * Process the password based on auth method requirements
     *
     * @return string
     */
    public function getPassword()
    {
        switch ($this->getAuthMethod())
        {
            case self::METHOD_LOGIN:
                return base64_encode( $this->transport->getPassword() );
                break;
        }
    }

    public function doPasswordCommand()
    {
         $this->transport->sendCommand(sprintf("%s\r\n", $this->getPassword() ));
    }
}