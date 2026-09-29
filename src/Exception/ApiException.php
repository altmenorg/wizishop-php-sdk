<?php

namespace WiziShop\SDK\Exception;

use GuzzleHttp\Exception\BadResponseException;

/**
 * Erreur de l'API, ou erreur réseau vers l'API.
 *
 * Sur une erreur réseau (délai dépassé, connexion refusée), il n'y a pas de
 * réponse : getResponse() rend null, et getErrorMessage() aussi.
 */
class ApiException extends BadResponseException
{
    /**
     * @return string|null Le champ « message » du corps JSON de la réponse
     */
    public function getErrorMessage()
    {
        if ($this->getResponse() === null) {
            return null;
        }

        $jsonReponse = json_decode((string) $this->getResponse()->getBody(), true);

        if (!is_array($jsonReponse)) {
            return null;
        }

        return array_key_exists('message', $jsonReponse) ? $jsonReponse['message'] : '';
    }
}
