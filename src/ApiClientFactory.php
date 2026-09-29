<?php

namespace WiziShop\SDK;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WiziShop\SDK\Exception\AuthenticationException;
use WiziShop\SDK\Model\JWT;

class Cache {                                                                   
    private $root;                                                              
    private $compile;                                                           
    private $ttl;                                                               

    public function __construct($options = []) {                                
        $this->options = array_merge(                                           
            array(                                                              
                'root' => sys_get_temp_dir(),                                   
                'ttl'  => false,                                                
            ),                                                                  
            $options                                                            
        );                                                                      
        $this->root = $this->options['root'];                                   
        $this->ttl = $this->options['ttl'];                                     
    }                                                                           

    public function set($key, $val, $ttl = null) {                              
        $ttl = $ttl === null ? $this->ttl : $ttl;                               
        $file = md5($key);                                                      
        $val = var_export(array(                                                
            'expiry' => $ttl ? time() + $ttl : false,                           
            'data' => $val,                                                     
        ), true);                                                               

        // Write to temp file first to ensure atomicity                         
        $tmp = $this->root . '/' . $file . '.' . uniqid('', true) . '.tmp';     
        file_put_contents($tmp, '<?php $val = ' . $val . ';', LOCK_EX);         

        $dest = $this->root . '/' . $file;                                      
        rename($tmp, $dest);                                                    
        opcache_invalidate($dest);                                              
    }                                                                           

    public function get($key) {                                                 
        @include $this->root . '/' . md5($key);           

        // Not found                                                            
        if (!isset($val)) return null;                                          

        // Found and not expired                                                
        if (!$val['expiry'] || $val['expiry'] > time()) return $val['data'];    

        // Expired, clean up                                                    
        $this->remove($key);                                                    
    }                                                                           

    public function remove($key) {                                              
        $dest = $this->root . '/' . md5($key);                                  
        if (@unlink($dest)) {                                                   
            // Invalidate cache if successfully written                         
            opcache_invalidate($dest);                                          
        }                                                                       
    }                                                                           
}

class ApiClientFactory
{
    /**
     * @param string $username Username
     * @param string $password Password
     * @param array $config Guzzle client configuration settings
     *
     * @return AuthenticatedApiClient
     */
   
    public static function authenticate($username, $password, array $config = [])
    {
        $tokenvar = $username."-token";
        $client = new Client([
            'base_uri' => AuthenticatedApiClient::API_URL,
        ]);

        try {
            $cache = new Cache();
	    $token = $cache->get($tokenvar);
            if ($token == ""){
              $authResponse = $client->post('/v3/auth/login', [
                  'json' => [
                      'username' => $username,
                      'password' => $password
                  ]
              ]);
             
              $jsonResponse = json_decode($authResponse->getBody(), true);
              $jwt = JWT::fromString($jsonResponse['token']);
              $cache->set($tokenvar, $jsonResponse['token'], 86400);
            } else {
              $token = $cache->get($tokenvar);
              $jwt = JWT::fromString($token);
            }
            $client = new AuthenticatedApiClient($jwt, $config);

            return $client;
        } catch (RequestException $e) {
            throw new AuthenticationException('Authentication problem', $e->getRequest(), $e->getResponse());
        }
    }
}
