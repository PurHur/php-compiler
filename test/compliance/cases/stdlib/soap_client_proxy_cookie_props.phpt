--TEST--
Stdlib: SoapClient proxy/digest/stream/cookies props (#23924, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'proxy_host' => 'proxy.example',
    'proxy_port' => 8080,
    'proxy_login' => 'pl',
    'proxy_password' => 'pp',
    'authentication' => SOAP_AUTHENTICATION_DIGEST,
]);
echo 'host=', (int) property_exists($c, '_proxy_host'), ':', (string) $c->_proxy_host, "\n";
echo 'port=', (int) property_exists($c, '_proxy_port'), ':', (int) $c->_proxy_port, "\n";
echo 'plogin=', (int) property_exists($c, '_proxy_login'), ':', (string) $c->_proxy_login, "\n";
echo 'ppass=', (int) property_exists($c, '_proxy_password'), ':', (string) $c->_proxy_password, "\n";
echo 'use_proxy_null=', (int) (null === $c->_use_proxy), "\n";
echo 'use_digest=', (int) property_exists($c, '_use_digest'), ':', (int) $c->_use_digest, "\n";
echo 'digest_null=', (int) (null === $c->_digest), "\n";
echo 'stream_null=', (int) (null === $c->_stream_context), "\n";
echo 'cookies_empty=', (int) (is_array($c->_cookies) && [] === $c->_cookies), "\n";
$c->__setCookie('a', '1');
echo 'cookies_set=', (int) (is_array($c->_cookies) && is_array($c->_cookies['a'] ?? null) && ($c->_cookies['a'][0] ?? '') === '1'), "\n";
$c->__setCookie('a', null);
echo 'cookies_unset=', (int) (is_array($c->_cookies) && !isset($c->_cookies['a'])), "\n";

$n = new SoapClient(null, ['location' => 'http://127.0.0.1/', 'uri' => 'http://test/']);
echo 'def_host_null=', (int) (null === $n->_proxy_host), "\n";
echo 'def_digest_flag=', (int) (false === $n->_use_digest), "\n";
?>
--EXPECT--
host=1:proxy.example
port=1:8080
plogin=1:pl
ppass=1:pp
use_proxy_null=1
use_digest=1:1
digest_null=1
stream_null=1
cookies_empty=1
cookies_set=1
cookies_unset=1
def_host_null=1
def_digest_flag=1
