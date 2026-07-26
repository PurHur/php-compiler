<?php
// repro #23247 — SoapClient::$sdl is ?Soap\Sdl after WSDL load
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
if (!property_exists($c, 'sdl')) {
    fwrite(STDERR, "property_exists sdl missing\n");
    exit(1);
}
if (!($c->sdl instanceof Soap\Sdl)) {
    fwrite(STDERR, "expected Soap\\Sdl, got ".get_debug_type($c->sdl)."\n");
    exit(1);
}

$non = new SoapClient(null, ['location' => 'http://127.0.0.1/', 'uri' => 'http://test/']);
if (!property_exists($non, 'sdl')) {
    fwrite(STDERR, "non-WSDL missing sdl property\n");
    exit(1);
}
if (null !== $non->sdl) {
    fwrite(STDERR, "non-WSDL sdl should be null\n");
    exit(1);
}

echo "ok\n";
@unlink($wsdl);
@rmdir($dir);
