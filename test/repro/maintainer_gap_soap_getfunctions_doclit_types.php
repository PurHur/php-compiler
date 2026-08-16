<?php
// Repro #31570 — document/literal __getFunctions uses XSD type names, not element names.
// Requires host ext/soap so SoapExtensionPolicy advertises.

$wsdl = __DIR__.'/../fixtures/soap/person_doclit.wsdl';
$c = new SoapClient($wsdl, [
    'cache_wsdl' => 0,
    'exceptions' => true,
]);
$fns = $c->__getFunctions();
echo $fns[0] ?? 'missing', "\n";
