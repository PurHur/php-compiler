--TEST--
Stdlib: SoapClient uri/style/use/location/trace/compression props (#23922, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'trace' => true,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
    'compression' => SOAP_COMPRESSION_ACCEPT,
]);
echo 'uri=', (int) property_exists($c, 'uri'), ':', (string) $c->uri, "\n";
echo 'loc=', (int) property_exists($c, 'location'), ':', (string) $c->location, "\n";
echo 'style=', (int) property_exists($c, 'style'), ':', (int) $c->style, "\n";
echo 'use=', (int) property_exists($c, 'use'), ':', (int) $c->use, "\n";
echo 'trace=', (int) property_exists($c, 'trace'), ':', (int) $c->trace, "\n";
echo 'comp=', (int) property_exists($c, 'compression'), ':', (int) $c->compression, "\n";

$n = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'defaults_trace=', (int) (false === $n->trace), "\n";
echo 'defaults_style_null=', (int) (null === $n->style), "\n";
echo 'defaults_comp_null=', (int) (null === $n->compression), "\n";

$n->__setLocation('http://example.com/');
echo 'setloc=', (string) $n->location, "\n";
?>
--EXPECT--
uri=1:http://test/
loc=1:http://127.0.0.1/
style=1:2
use=1:1
trace=1:1
comp=1:32
defaults_trace=1
defaults_style_null=1
defaults_comp_null=1
setloc=http://example.com/
