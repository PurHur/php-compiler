<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

// DIGEST without challenge must not fall back to Basic (#20340 / php-src use_digest).
$c0 = new SoapClient($wsdl, [
  'location' => $resp,
  'uri' => 'http://example.com/echo',
  'trace' => 1,
  'login' => 'alice',
  'password' => 's3cret',
  'authentication' => SOAP_AUTHENTICATION_DIGEST,
]);
// Temporarily hide challenge sidecar if present by using a copy path without sidecar:
$tmp = sys_get_temp_dir() . '/soap_digest_no_challenge_' . getmypid() . '.xml';
copy($resp, $tmp);
$c0 = new SoapClient($wsdl, [
  'location' => $tmp,
  'uri' => 'http://example.com/echo',
  'trace' => 1,
  'login' => 'alice',
  'password' => 's3cret',
  'authentication' => SOAP_AUTHENTICATION_DIGEST,
]);
$c0->__soapCall('echo', [['input' => 'hi']]);
$h0 = $c0->__getLastRequestHeaders();
echo (is_string($h0) && !str_contains($h0, 'Authorization:')) ? 'nochallenge=1' : 'nochallenge=0';
echo "\n";
@unlink($tmp);

$c = new SoapClient($wsdl, [
  'location' => $resp,
  'uri' => 'http://example.com/echo',
  'trace' => 1,
  'login' => 'alice',
  'password' => 's3cret',
  'authentication' => SOAP_AUTHENTICATION_DIGEST,
]);
$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'Authorization: Digest ')) ? 'digest=1' : 'digest=0';
echo "\n";
echo (is_string($h) && str_contains($h, 'username="alice"')) ? 'user=1' : 'user=0';
echo "\n";
echo (is_string($h) && str_contains($h, 'realm="SoapRealm"')) ? 'realm=1' : 'realm=0';
echo "\n";
echo (is_string($h) && !str_contains($h, 'Authorization: Basic ')) ? 'not_basic=1' : 'not_basic=0';
echo "\n";
