--TEST--
Stdlib: Soap\Url opaque after PROFILE=8.4 (#23926 pairs with unit urlPayload)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'url_class=', (int) class_exists('Soap\\Url', false), "\n";
echo 'httpurl_null=', (int) (null === $c->httpurl), "\n";
echo 'is_url=', (int) ($c->httpurl instanceof Soap\Url), "\n";
?>
--EXPECT--
url_class=1
httpurl_null=1
is_url=0
