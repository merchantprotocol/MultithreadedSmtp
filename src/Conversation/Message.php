<?php

namespace Merchantprotocol\MultithreadedSmtp\Conversation;

use Merchantprotocol\MultithreadedSmtp\Conversation;

 /*
 * @package Acelle\Library
 */
class Message extends Conversation
{
    /**
     * Cleans the FROM email and name
     *
     * @return void
     */
    public function doMailFromCommand()
    {
        $addresses = $this->message->getFrom();
        foreach($addresses as $address => $name) {
            $address = $this->transport->addressEncoder->encodeString($address);
            $handlers = $this->transport->getActiveHandlers();
            $params = [];
            foreach ($handlers as $handler) {
                $params = array_merge($params, (array) $handler->getMailParams());
            }
            $paramStr = !empty($params) ? ' '.implode(' ', $params) : '';
            $this->transport->sendCommand(sprintf("MAIL FROM:<%s>%s\r\n", $address, $paramStr));
        }
    }

    /**
     * Cleans the email and name using the swift given classes
     *
     * @return void
     */
    public function doRcptToCommand()
    {
        $address = $this->message->getTo();
        $address = $this->transport->addressEncoder->encodeString($address);
        $handlers = $this->transport->getActiveHandlers();
        $params = [];
        foreach ($handlers as $handler) {
            $params = array_merge($params, (array) $handler->getRcptParams());
        }
        $paramStr = !empty($params) ? ' '.implode(' ', $params) : '';
        $this->transport->sendCommand(sprintf("RCPT TO:<%s>%s\r\n", $address, $paramStr));
    }

    /**
     * sends the full email
     *
     * @param [type] $message
     * @return void
     */
    public function doFullMailCommand($message)
    {
        $address = $this->message->getFrom();
        $address = $this->message->getTo();
        $subject = $this->message->getSubject();
        $body = $this->message->getBody();

        $this->transport->sendCommand(sprintf("From: %s <%s>\r\n", $address, $paramStr));
        $this->transport->sendCommand(sprintf("To: %s <%s>\r\n", $address, $paramStr));
        $this->transport->sendCommand(sprintf("Subject: %s\r\n", $subject));
        $this->transport->sendCommand("\r\n");
        $this->transport->sendCommand(sprintf("%s\r\n", $body));
        $this->transport->sendCommand("\r\n");
        $this->transport->sendCommand(".\r\n");
    }
}