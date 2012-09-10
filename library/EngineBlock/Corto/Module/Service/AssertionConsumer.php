<?php

class EngineBlock_Corto_Module_Service_AssertionConsumer extends EngineBlock_Corto_Module_Service_Abstract
{
    const RESPONSE_CACHE_TYPE_IN  = 'in';
    const RESPONSE_CACHE_TYPE_OUT = 'out';

    public function serve()
    {
        $receivedResponse = $this->_server->getBindingsModule()->receiveResponse();

        // Get the ID of the Corto Request message
        if (!$receivedResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'InResponseTo']) {
            $message = "Unsollicited assertion (no InResponseTo in message) not supported!";
            throw new EngineBlock_Corto_Module_Services_Exception($message);
        }

        $receivedRequest = $this->_server->getReceivedRequestFromResponse($receivedResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'InResponseTo']);

        // Cache the response
        if ($this->_server->getCurrentEntitySetting('keepsession', false)) {
            $this->_cacheResponse($receivedRequest, $receivedResponse, self::RESPONSE_CACHE_TYPE_IN);
        }

        $this->_server->filterInputAssertionAttributes($receivedResponse, $receivedRequest);

        $processingEntities = $this->_getReceivedResponseProcessingEntities($receivedRequest, $receivedResponse);
        if (!empty($processingEntities)) {
            $firstProcessingEntity = array_shift($processingEntities);
            $_SESSION['Processing'][$receivedRequest[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID']]['RemainingEntities']   = $processingEntities;
            $_SESSION['Processing'][$receivedRequest[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID']]['OriginalDestination'] = $receivedResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'Destination'];
            $_SESSION['Processing'][$receivedRequest[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID']]['OriginalIssuer']      = $receivedResponse['saml:Assertion']['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX];
            $_SESSION['Processing'][$receivedRequest[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID']]['OriginalBinding']     = $receivedResponse[EngineBlock_Corto_XmlToArray::PRIVATE_PFX]['ProtocolBinding'];

            $this->_server->setProcessingMode();
            $newResponse = $this->_server->createEnhancedResponse($receivedRequest, $receivedResponse);

            // Change the destiny of the received response
            $newResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'InResponseTo']          = $receivedResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'InResponseTo'];
            $newResponse[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'Destination']           = $firstProcessingEntity['Location'];
            $newResponse[EngineBlock_Corto_XmlToArray::PRIVATE_PFX]['ProtocolBinding']  = $firstProcessingEntity['Binding'];
            $newResponse[EngineBlock_Corto_XmlToArray::PRIVATE_PFX]['Return']           = $this->_server->getCurrentEntityUrl('processedAssertionConsumerService');
            $newResponse[EngineBlock_Corto_XmlToArray::PRIVATE_PFX]['paramname']        = 'SAMLResponse';

            $responseAssertionAttributes = &$newResponse['saml:Assertion']['saml:AttributeStatement'][0]['saml:Attribute'];
            $attributes = EngineBlock_Corto_XmlToArray::attributes2array($responseAssertionAttributes);
            $attributes['ServiceProvider'] = array($receivedRequest['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX]);
            $responseAssertionAttributes = EngineBlock_Corto_XmlToArray::array2attributes($attributes);

            $this->_server->getBindingsModule()->send($newResponse, $firstProcessingEntity);
        }
        else {
            // Cache the response
            if ($this->_server->getCurrentEntitySetting('keepsession', false)) {
                $this->_cacheResponse($receivedRequest, $receivedResponse, self::RESPONSE_CACHE_TYPE_OUT);
            }

            $newResponse = $this->_server->createEnhancedResponse($receivedRequest, $receivedResponse);
            $this->_server->sendResponseToRequestIssuer($receivedRequest, $newResponse);
        }
    }

    protected function _cacheResponse(array $receivedRequest, array $receivedResponse, $type)
    {
        $requestIssuerEntityId  = $receivedRequest['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX];
        $responseIssuerEntityId = $receivedResponse['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX];
        if (!isset($_SESSION['CachedResponses'])) {
            $_SESSION['CachedResponses'] = array();
        }
        $_SESSION['CachedResponses'][] = array(
            'sp'            => $requestIssuerEntityId,
            'idp'           => $responseIssuerEntityId,
            'type'          => $type,
            'response'      => $receivedResponse,
            'vo'            => $this->_server->getVirtualOrganisationContext()
        );
        return $_SESSION['CachedResponses'][count($_SESSION['CachedResponses']) - 1];
    }

    protected function _getReceivedResponseProcessingEntities(array $receivedRequest, array $receivedResponse)
    {
        $currentEntityProcessing = $this->_server->getCurrentEntitySetting('Processing', array());

        $remoteEntity = $this->_server->getRemoteEntity($receivedRequest['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX]);

        $processing = $currentEntityProcessing;
        if (isset($remoteEntity['Processing'])) {
            $processing += $remoteEntity['Processing'];
        }

        return $processing;
    }
}