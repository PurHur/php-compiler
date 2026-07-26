<?php
// repro — SoapClient::$sdl should be ?Soap\Sdl after WSDL construct (php-src 8.4+)
declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_soap_sdl_'.getmypid();
@mkdir($dir);
$wsdl = $dir.'/s.wsdl';
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
$v = $c->sdl ?? null;
echo 'null=', (int) (null === $v), "\n";
echo 'is_sdl=', (int) ($v instanceof Soap\Sdl), "\n";
echo 'type=', get_debug_type($v), "\n";
@unlink($wsdl);
@rmdir($dir);
