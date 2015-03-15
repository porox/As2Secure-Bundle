<?php

namespace TechData\AS2SecureBundle\Services;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use TechData\AS2SecureBundle\Events\MessageReceived;
use TechData\AS2SecureBundle\Factories\Partner as PartnerFactory;
use TechData\AS2SecureBundle\Factories\Request as RequestFactory;
use TechData\AS2SecureBundle\Models\Header;
use TechData\AS2SecureBundle\Models\Server;

/**
 * Description of AS2
 *
 * @author wpigott
 */
class AS2
{

    CONST EVENT_MESSAGE_RECEIVED = 'message_received';

    /**
     *
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     *
     * @var PartnerFactory
     */
    private $partnerFactory;

    /**
     * @var Server
     */
    private $as2Server;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    public function __construct(EventDispatcherInterface $eventDispatcher, Server $server, RequestFactory $requestFactory, PartnerFactory $partnerFactory)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->as2Server = $server;
        $this->requestFactory = $requestFactory;
        $this->partnerFactory = $partnerFactory;

    }

    public function handleRequest(Request $request)
    {
        // Convert the symfony request to a as2s request
        $as2Request = $this->requestFactory->build($request->getContent(), new Header($request->headers->all()));

        // Take the request and lets AS2S handle it
        $as2Response = $this->as2Server->handle($as2Request);

        // Get the partner and verify they are authorized
        $partner = $as2Response->getPartnerFrom();
        // @TODO Authorize the partner.

        // process all EDI-X12 messages contained in the AS2 payload
        $response_object = $as2Response->getObject();
        try {
            // the AS2 payload may be further encoded, try to decode it.
            $response_object->decode();
        } catch (\Exception $e) {
            // there was an exception while attemptiong to decode, so the message was probably not encoded... ignore the exception
        }
        $files = $response_object->getFiles();
        foreach ($files as $file) {
            // We have an incoming message.  Lets fire the event for it.
            $event = new MessageReceived();
            $event->setMessage(file_get_contents($file['path']));
            $this->eventDispatcher->dispatch(MessageReceived::EVENT, $event);
        }
    }

    public function sendMessage()
    {

    }

}

/*




    public function sendMessage() {
        // process request to build outbound AS2 message to VAR
        if (trim($_REQUEST['message']) != '') {
            // query for requested receiving VAR's profile information
            $query = "	SELECT instance_id, edi_incoming_auth, edi_as2_id_prod, company_name
				FROM bih1241 AS var
				WHERE instance_id=$1	";
            $result = eas_query_params($query, array($_REQUEST['var_id']));
            $var = pg_fetch_assoc($result->recordset);
            if ($var && (trim($_REQUEST['auth']) == trim($var['edi_incoming_auth']))) {
                // initialize outbound AS2Message object
                $params = array('partner_from' => '081940553STM1',
                    'partner_to' => $var['edi_as2_id_prod']);
                $message = new AS2Message(false, $params);

                // initialize AS2Adapter for public key encryption between StreamOne and the receiving VAR
                $adapter = new AS2Adapter('081940553STM1', $var['edi_as2_id_prod']);

                // write the EDI message that will be sent to a temp file, then use the AS2 adapter to encrypt it
                $tmp_file = $adapter->getTempFilename();
                file_put_contents($tmp_file, $_REQUEST['message']);
                $message->addFile($tmp_file, 'application/edi-x12');
                $message->encode();

                // initialize outbound AS2 client
                $client = new AS2Client();

                // send AS2 message
                $result = $client->sendRequest($message);
                $result_text = print_r($result, true);
                @mail('mike.kristopeit@etelos-inc.com', 'AS2/EDI-X12 message sent', "{$_SERVER['SERVER_NAME']}\n\nVAR: {$var['company_name']} (ID: {$var['instance_id']}, XID: {$var['var_xid']})\n\nmessage: \n{$_REQUEST['message']}\n\nresponse: \n$result_text");
                echo 'sent';
            } else {
                // bad request - either the requested receiving VAR does not exist, or an invalid auth code was provided
                header('HTTP/1.1 400 Bad Request', true, 400);
                echo 'Bad Request';
                exit;
            }
        } else {
            // bad request - blank message
            header('HTTP/1.1 400 Bad Request', true, 400);
            echo 'Bad Request';
            exit;
        }
    }
 *
