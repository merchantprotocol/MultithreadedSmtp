<?php

namespace Merchantprotocol\MultithreadedSmtp\Conversation;

use Merchantprotocol\MultithreadedSmtp\Conversation;

 /*
 * @package Acelle\Library
 */
class Hello extends Conversation
{
    CONST METHOD_LOGIN = 'LOGIN';

    public function start()
    {
        // Call the onResponse callback when the response code is received
        $this->onDone('250', [$this, parseCapabilitiesResponse]);

        $this->doHelloCommand();
    }

    public function doHelloCommand()
    {
        $this->transport->sendCommand(sprintf("EHLO %s\r\n", $this->transport->domain));
    }

    public function parseCapabilitiesResponse($responseCode, $responseMessage)
    {
        $case = $responseMessage;

        if (strpos($responseMessage, 'SIZE') !== false) {
            $case = 'SIZE';
        } elseif (strpos($responseMessage, 'AUTH') !== false) {
            $case = 'AUTH';
        }

        switch ($case) {
            case 'SIZE':
                $this->capabilities['SIZE'] = substr($responseMessage, 4);
                break;
            case 'STARTTLS':
                $this->capabilities['STARTTLS'] = true;
                break;
            case 'AUTH':
                // Extract the supported authentication mechanisms from the response
                if (preg_match('/AUTH\s+(.*)/', $responseMessage, $matches)) {
                    if (count($matches) >1 ) {
                        $authMechanisms = explode(' ', $matches[1]);

                        // Add the supported authentication mechanisms to the capabilities array
                        foreach ($authMechanisms as $mechanism) {
                            $this->capabilities['AUTH'][] = $mechanism;
                        }
                    }
                }
                break;
            case 'PIPELINING':
                $this->capabilities['PIPELINING'] = true;
                break;
            case '8BITMIME':
                $this->capabilities['8BITMIME'] = true;
                break;
            case 'SMTPUTF8':
                $this->capabilities['SMTPUTF8'] = true;
                break;
            case 'ENHANCEDSTATUSCODES':
                $this->capabilities['ENHANCEDSTATUSCODES'] = true;
                break;
            case 'DSN':
                $this->capabilities['DSN'] = true;
                break;

            // Add more cases for other specific response messages
            default:
                // determine what provider we're dealing with
                if (!$this->transport->params['espProvider']) {
                    // Extract the domain
                    $domain = '';
                    if (preg_match('/(\b\S+\.\S+)/', $responseMessage, $matches)) {
                        if (count($matches) == 2) {
                            $domain = $matches[1];
                            if (preg_match('/[^.]+\.[^.]+$/', $domain, $matches)) {
                                $domain = $matches[0];
                            }
                        }
                    }
                    $this->transport->params['espProvider'] = $this->determineESP($domain, $responseMessage);
                }
                // Handle unrecognized response message
                $this->debug('Uncaught response ^^ ', 'yellow');
                break;
        }
    }
}