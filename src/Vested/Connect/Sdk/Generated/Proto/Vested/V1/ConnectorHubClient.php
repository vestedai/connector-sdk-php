<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Vested\Connect\Sdk\Generated\Proto\Vested\V1;

/**
 */
class ConnectorHubClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\BidiStreamingCall
     */
    public function Connect($metadata = [], $options = []) {
        return $this->_bidiRequest('/vested.v1.ConnectorHub/Connect',
        ['\Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg','decode'],
        $metadata, $options);
    }

}
