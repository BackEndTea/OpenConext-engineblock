<?php

class EngineBlock_Corto_Module_Service_ProcessConsent
    implements EngineBlock_Corto_Module_Service_ServiceInterface
{
    /**
     * @var \EngineBlock_Corto_ProxyServer
     */
    protected $_server;

    /**
     * @var \EngineBlock_Corto_XmlToArray
     */
    protected $_xmlConverter;

    /**
     * @var EngineBlock_Corto_Model_Consent_Factory
     */
    private $_consentFactory;

    /**
     * @param EngineBlock_Corto_ProxyServer $server
     * @param EngineBlock_Corto_XmlToArray $xmlConverter
     * @param EngineBlock_Corto_Model_Consent_Factory $consentFactory
     */
    public function __construct(
        EngineBlock_Corto_ProxyServer $server,
        EngineBlock_Corto_XmlToArray $xmlConverter,
        EngineBlock_Corto_Model_Consent_Factory $consentFactory
    ) {
        $this->_server = $server;
        $this->_xmlConverter = $xmlConverter;
        $this->_consentFactory = $consentFactory;
    }

    public function serve($serviceName)
    {
        if (!isset($_SESSION['consent'])) {
            throw new EngineBlock_Corto_Module_Services_SessionLostException('Session lost after consent');
        }
        if (!isset($_SESSION['consent'][$_POST['ID']]['response'])) {
            throw new EngineBlock_Corto_Module_Services_SessionLostException(
                "Stored response for ResponseID '{$_POST['ID']}' not found"
            );
        }
        /** @var SAML2_Response|EngineBlock_Saml2_ResponseAnnotationDecorator $response */
        $response = $_SESSION['consent'][$_POST['ID']]['response'];

        $request = $this->_server->getReceivedRequestFromResponse($response);
        $serviceProvider = $this->_server->getRepository()->fetchServiceProviderByEntityId($request->getIssuer());

        $destinationMetadata = EngineBlock_SamlHelper::getDestinationSpMetadata(
            $serviceProvider,
            $request,
            $this->_server->getRepository()
        );

        if (!isset($_POST['consent']) || $_POST['consent'] !== 'yes') {
            throw new EngineBlock_Corto_Exception_NoConsentProvided('No consent given...');
        }

        $attributes = $response->getAssertion()->getAttributes();
        $consentRepository = $this->_consentFactory->create($this->_server, $response, $attributes);

        if (!$consentRepository->explicitConsentWasGivenFor($serviceProvider)) {
            $consentRepository->giveExplicitConsentFor($destinationMetadata);
        }

        $response->setConsent(SAML2_Const::CONSENT_OBTAINED);
        $response->setDestination($response->getReturn());
        $response->setDeliverByBinding('INTERNAL');

        $this->_server->getBindingsModule()->send(
            $response,
            $serviceProvider
        );
    }
}
