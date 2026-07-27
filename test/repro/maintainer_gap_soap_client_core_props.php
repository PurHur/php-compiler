<?php
declare(strict_types=1);
// Maintainer gap: SoapClient core stub props undeclared (ext/soap/soap.stub.php)
$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'trace' => true,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
    'compression' => SOAP_COMPRESSION_ACCEPT,
]);
foreach (['uri', 'style', 'use', 'location', 'trace', 'compression'] as $p) {
    $ex = property_exists($c, $p);
    echo $p, ' exists=', (int) $ex;
    if ($ex) {
        $v = $c->$p;
        echo ' type=', get_debug_type($v);
        if (is_scalar($v) || null === $v) {
            echo ' val=', var_export($v, true);
        }
    }
    echo "\n";
}
