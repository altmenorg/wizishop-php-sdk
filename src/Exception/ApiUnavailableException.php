<?php

namespace WiziShop\SDK\Exception;

/**
 * L'API ne répond pas : délai dépassé, connexion impossible, 502/503/504 — ou
 * le coupe-circuit est ouvert (CircuitBreaker) et la requête n'est même pas
 * partie. Sous-classe d'ApiException : un catch existant la rattrape.
 *
 * getDownSince() : depuis quand l'API est tenue pour en panne (timestamp).
 */
class ApiUnavailableException extends ApiException
{
    /**
     * @var int|null
     */
    private $downSince;

    public function __construct($message, $request, $response = null, $previous = null, $downSince = null)
    {
        parent::__construct($message, $request, $response, $previous);
        $this->downSince = $downSince;
    }

    /**
     * @return int|null
     */
    public function getDownSince()
    {
        return $this->downSince;
    }
}
