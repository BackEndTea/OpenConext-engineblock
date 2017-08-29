<?php

use OpenConext\Component\EngineBlockMetadata\Entity\ServiceProvider;
use OpenConext\EngineBlock\Authentication\Value\ConsentType;
use Psr\Log\LoggerInterface;

class EngineBlock_Corto_Model_Consent
{
    /**
     * @var string
     */
    private $_tableName;

    /**
     * @var bool
     */
    private $_mustStoreValues;

    /**
     * @var EngineBlock_Saml2_ResponseAnnotationDecorator
     */
    private $_response;
    /**
     * @var array
     */
    private $_responseAttributes;

    /**
     * @var EngineBlock_Database_ConnectionFactory
     */
    private $_databaseConnectionFactory;

    /**
     * @var LoggerInterface
     */
    private $_log;

    /**
     * @param string $tableName
     * @param bool $mustStoreValues
     * @param EngineBlock_Saml2_ResponseAnnotationDecorator $response
     * @param array $responseAttributes
     * @param EngineBlock_Database_ConnectionFactory $databaseConnectionFactory
     * @param LoggerInterface $log
     */
    public function __construct(
        $tableName,
        $mustStoreValues,
        EngineBlock_Saml2_ResponseAnnotationDecorator $response,
        array $responseAttributes,
        EngineBlock_Database_ConnectionFactory $databaseConnectionFactory,
        LoggerInterface $log
    )
    {
        $this->_tableName = $tableName;
        $this->_mustStoreValues = $mustStoreValues;
        $this->_response = $response;
        $this->_responseAttributes = $responseAttributes;
        $this->_databaseConnectionFactory = $databaseConnectionFactory;
        $this->_log = $log;
    }

    public function explicitConsentWasGivenFor(ServiceProvider $serviceProvider) {
        return $this->_hasStoredConsent($serviceProvider, ConsentType::TYPE_EXPLICIT);
    }

    public function implicitConsentWasGivenFor(ServiceProvider $serviceProvider) {
        return $this->_hasStoredConsent($serviceProvider, ConsentType::TYPE_IMPLICIT);
    }

    public function giveExplicitConsentFor(ServiceProvider $serviceProvider)
    {
        return $this->_storeConsent($serviceProvider, ConsentType::TYPE_EXPLICIT);
    }

    public function giveImplicitConsentFor(ServiceProvider $serviceProvider)
    {
        return $this->_storeConsent($serviceProvider, ConsentType::TYPE_IMPLICIT);
    }

    public function countTotalConsent()
    {
        $dbh = $this->_getConsentDatabaseConnection();
        $hashedUserId = sha1($this->_getConsentUid());
        $query = "SELECT COUNT(*) FROM consent where hashed_user_id = ?";
        $parameters = array($hashedUserId);
        $statement = $dbh->prepare($query);
        if (!$statement) {
            throw new EngineBlock_Exception(
                "Unable to create a prepared statement to count consent?!", EngineBlock_Exception::CODE_ALERT
            );
        }
        /** @var $statement PDOStatement */
        $statement->execute($parameters);
        return (int)$statement->fetchColumn();
    }

    /**
     * @return bool|PDO
     */
    protected function _getConsentDatabaseConnection()
    {
        return $this->_databaseConnectionFactory->create();
    }

    protected function _getConsentUid()
    {
        return $this->_response->getNameIdValue();
    }

    protected function _getAttributesHash($attributes)
    {
        $hashBase = NULL;
        if ($this->_mustStoreValues) {
            ksort($attributes);
            $hashBase = serialize($attributes);
        } else {
            $names = array_keys($attributes);
            sort($names);
            $hashBase = implode('|', $names);
        }
        return sha1($hashBase);
    }

    private function _storeConsent(ServiceProvider $serviceProvider, $consentType)
    {
        $dbh = $this->_getConsentDatabaseConnection();
        if (!$dbh) {
            return false;
        }

        $query = "INSERT INTO consent (hashed_user_id, service_id, attribute, consent_type)
                  VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE attribute=VALUES(attribute), consent_type=VALUES(consent_type)";
        $parameters = array(
            sha1($this->_getConsentUid()),
            $serviceProvider->entityId,
            $this->_getAttributesHash($this->_responseAttributes),
            $consentType,
        );

        $statement = $dbh->prepare($query);
        if (!$statement) {
            throw new EngineBlock_Exception(
                "Unable to create a prepared statement to insert consent?!",
                EngineBlock_Exception::CODE_CRITICAL
            );
        }

        /** @var $statement PDOStatement */
        if (!$statement->execute($parameters)) {
            throw new EngineBlock_Corto_Module_Services_Exception(
                "Error storing consent: " . var_export($statement->errorInfo(), true),
                EngineBlock_Exception::CODE_CRITICAL
            );
        }

        $this->_log->info('Stored consent in database', array(
            'hashed_user_id' => $parameters[0],
            'service_id' => $parameters[1],
            'attribute' => $parameters[2],
            'consent_type' => $parameters[3]
        ));

        return true;
    }

    private function _hasStoredConsent(ServiceProvider $serviceProvider, $consentType)
    {
        try {
            $dbh = $this->_getConsentDatabaseConnection();
            if (!$dbh) {
                return false;
            }

            $attributesHash = $this->_getAttributesHash($this->_responseAttributes);

            $query = "SELECT * FROM {$this->_tableName} WHERE hashed_user_id = ? AND service_id = ? AND attribute = ? AND consent_type = ?";
            $hashedUserId = sha1($this->_getConsentUid());
            $parameters = array(
                $hashedUserId,
                $serviceProvider->entityId,
                $attributesHash,
                $consentType,
            );

            $this->_log->info('Looking for consent in database', array(
                'hashed_user_id' => $parameters[0],
                'service_id' => $parameters[1],
                'attribute' => $parameters[2],
                'consent_type' => $parameters[3]
            ));

            /** @var $statement PDOStatement */
            $statement = $dbh->prepare($query);
            $statement->execute($parameters);
            $rows = $statement->fetchAll();

            if (count($rows) < 1) {
                $this->_log->info('No stored consent found');
                return false;
            }
            $this->_log->info('Stored consent found');

            return true;
        } catch (PDOException $e) {
            throw new EngineBlock_Corto_ProxyServer_Exception(
                "Consent retrieval failed! Error: " . $e->getMessage(),
                EngineBlock_Exception::CODE_ALERT
            );
        }
    }
}
