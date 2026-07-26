--TEST--
Stdlib: SoapClient::$httpurl declared null until HTTP (#23246, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'exists=', (int) property_exists($c, 'httpurl'), "\n";
echo 'null=', (int) (null === $c->httpurl), "\n";
echo 'is_url=', (int) ($c->httpurl instanceof Soap\Url), "\n";
?>
--EXPECT--
exists=1
null=1
is_url=0
