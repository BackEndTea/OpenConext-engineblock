<?php

class EngineBlock_Corto_Filter_Command_ProvisionUser extends EngineBlock_Corto_Filter_Command_Abstract
{
    /**
     * This command modifies the response
     *
     * @return array
     */
    public function getResponse()
    {
        return $this->_response;
    }

    /**
     * This command modifies the collabPersonId
     *
     * @return string
     */
    public function getCollabPersonId()
    {
        return $this->_collabPersonId;
    }

    public function execute()
    {
        $userDirectory = EngineBlock_ApplicationSingleton::getInstance()->getDiContainer()->getUserDirectory();

        $subjectIdField = EngineBlock_ApplicationSingleton::getInstance()->getConfigurationValue(
            'subjectIdAttribute',
           'uid+sho' 
        );

        $user = $userDirectory->identifyUser($this->_responseAttributes, $subjectIdField);

        $collabPersonIdValue = $user->getCollabPersonId()->getCollabPersonId();
        $this->setCollabPersonId($collabPersonIdValue);

        $this->_response->setCollabPersonId($collabPersonIdValue);
        $this->_response->setOriginalNameId($this->_response->getAssertion()->getNameId());

        // Adjust the NameID in the OLD response (for consent), set the collab:person uid
        $this->_response->getAssertion()->setNameId(array(
            'Value' => $collabPersonIdValue,
            'Format' => SAML2_Const::NAMEID_PERSISTENT,
        ));
    }
}
