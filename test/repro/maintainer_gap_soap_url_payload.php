<?php
declare(strict_types=1);
// Maintainer gap: Soap\Url php_url payload (#23926) — exercised via SoapExtensionPolicyTest
// (VmSoapOpaque::urlPayload after attachHttpUrl). Userland surface: Soap\Url class + httpurl.
$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'url_class=', (int) class_exists('Soap\\Url', false), "\n";
echo 'httpurl_null=', (int) (null === $c->httpurl), "\n";
