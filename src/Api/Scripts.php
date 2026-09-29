<?php

namespace WiziShop\SDK\Api;

/**
 * Scripts injectés dans la boutique.
 */
trait Scripts
{
    /**
     * @return array
     */
    public function getScripts()
    {
        return $this->requestJson('GET', 'scripts');
    }

    /**
     * @param array $script
     *
     * @return array
     */
    public function createScript(array $script)
    {
        return $this->requestJson('POST', 'scripts', ['json' => $script]);
    }

    /**
     * @param int $scriptId
     * @param array $script
     *
     * @return array
     */
    public function updateScript($scriptId, array $script)
    {
        return $this->requestJson('PUT', sprintf('scripts/%s', $scriptId), ['json' => $script]);
    }

    /**
     * @param int $scriptId
     *
     * @return bool
     */
    public function deleteScript($scriptId)
    {
        return $this->requestStatus('DELETE', sprintf('scripts/%s', $scriptId));
    }
}
