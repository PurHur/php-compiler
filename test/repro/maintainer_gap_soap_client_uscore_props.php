<?php
declare(strict_types=1);
$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'login' => 'user',
    'password' => 'pass',
    'encoding' => 'UTF-8',
    'classmap' => ['Book' => 'stdClass'],
    'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
    'connection_timeout' => 5,
    'keep_alive' => false,
    'ssl_method' => SOAP_SSL_METHOD_TLS,
    'soap_version' => SOAP_1_2,
    'exceptions' => false,
    'user_agent' => 'UA/1',
]);
foreach ([
    '_login', '_password', '_encoding', '_classmap', '_features',
    '_connection_timeout', '_keep_alive', '_ssl_method', '_soap_version',
    '_exceptions', '_user_agent',
] as $p) {
    $ex = property_exists($c, $p);
    echo $p, ' exists=', (int) $ex;
    if ($ex) {
        $v = $c->$p;
        echo ' type=', get_debug_type($v);
        if (is_scalar($v) || null === $v) {
            echo ' val=', var_export($v, true);
        } elseif (is_array($v)) {
            echo ' keys=', implode(',', array_keys($v));
        }
    }
    echo "\n";
}
