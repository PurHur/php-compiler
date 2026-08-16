<?php
declare(strict_types=1);
$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'proxy_host' => 'proxy.example',
    'proxy_port' => 8080,
    'proxy_login' => 'pl',
    'proxy_password' => 'pp',
    'authentication' => SOAP_AUTHENTICATION_DIGEST,
]);
foreach ([
    '_proxy_host', '_proxy_port', '_proxy_login', '_proxy_password',
    '_use_proxy', '_use_digest', '_digest', '_stream_context', '_cookies',
] as $p) {
    $ex = property_exists($c, $p);
    echo $p, ' exists=', (int) $ex;
    if ($ex) {
        $v = $c->$p;
        echo ' type=', get_debug_type($v);
        if (is_scalar($v) || null === $v) {
            echo ' val=', var_export($v, true);
        } elseif (is_array($v)) {
            echo ' count=', count($v);
        }
    }
    echo "\n";
}
$c->__setCookie('a', '1');
echo 'cookies_after=', is_array($c->_cookies) && is_array($c->_cookies['a'] ?? null) && ($c->_cookies['a'][0] ?? '') === '1' ? '1' : '0', "\n";
