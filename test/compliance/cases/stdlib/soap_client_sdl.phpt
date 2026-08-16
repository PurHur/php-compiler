--TEST--
Stdlib: SoapClient::$sdl is Soap\Sdl after WSDL (#23247/#23905, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phpc_soap_sdl_c_' . getmypid();
@mkdir($dir);
$wsdl = $dir . '/s.wsdl';
file_put_contents($wsdl, '<?xml version="1.0"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"
  xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
  xmlns:tns="http://test/" targetNamespace="http://test/" name="T">
  <message name="mIn"/><message name="mOut"/>
  <portType name="P"><operation name="ping"><input message="tns:mIn"/><output message="tns:mOut"/></operation></portType>
  <binding name="B" type="tns:P"><soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>
    <operation name="ping"><soap:operation soapAction="ping"/><input/><output/></operation></binding>
  <service name="S"><port name="Port" binding="tns:B"><soap:address location="http://127.0.0.1/"/></port></service>
</definitions>');

$c = new SoapClient($wsdl);
echo 'exists=', (int) property_exists($c, 'sdl'), "\n";
echo 'is_sdl=', (int) ($c->sdl instanceof Soap\Sdl), "\n";
$fns = $c->__getFunctions();
echo 'has_ping=', (int) (is_array($fns) && in_array('void ping()', $fns, true)), "\n";

$n = new SoapClient(null, ['location' => 'http://127.0.0.1/', 'uri' => 'http://test/']);
echo 'non_null=', (int) (null === $n->sdl), "\n";
@unlink($wsdl);
@rmdir($dir);
?>
--EXPECT--
exists=1
is_sdl=1
has_ping=1
non_null=1
