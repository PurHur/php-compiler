<?php
echo 'class=', class_exists('SoapClient') ? 1 : 0, "\n";
echo 'ext=', extension_loaded('soap') ? 1 : 0, "\n";
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';
$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'exceptions' => true,
    'trace' => true,
]);
$out = $client->__soapCall('echo', [['input' => 'hello']]);
echo 'out=', var_export($out, true), "\n";
$fns = $client->__getFunctions();
echo 'fns=', is_array($fns) && isset($fns[0]) && $fns[0] === 'echoResponse echo(echo $parameters)' ? 1 : 0, "\n";
