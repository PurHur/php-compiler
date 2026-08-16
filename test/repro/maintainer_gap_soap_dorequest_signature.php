<?php
// Repro #31568 — SoapClient::__doRequest must match soap.stub.php (location + oneWay).
// Requires host ext/soap so SoapExtensionPolicy advertises (php8.2-soap).

$r = new ReflectionMethod('SoapClient', '__doRequest');
$names = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $names[] = $p->getName()
        .':'
        .(null !== $t ? (string) $t : '')
        .($p->isOptional() ? '=' : '');
}
echo 'params=', implode(',', $names), "\n";

class SoapDoRequestOverrideProbe extends SoapClient
{
    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        return '<?xml version="1.0"?><x/>';
    }
}

echo 'override=ok', "\n";
