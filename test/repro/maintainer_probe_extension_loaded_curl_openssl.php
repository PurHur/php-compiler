<?php

declare(strict_types=1);

/**
 * extension_loaded('curl'/'openssl') vs handle/object classes — Zend parity (#16750).
 */

$fail = 0;

$curlLoaded = extension_loaded('curl');
$opensslLoaded = extension_loaded('openssl');
$curlHandle = class_exists('CurlHandle', false);
$opensslCert = class_exists('OpenSSLCertificate', false);

echo "curl_loaded=", (int) $curlLoaded, "\n";
echo "openssl_loaded=", (int) $opensslLoaded, "\n";
echo "CurlHandle=", (int) $curlHandle, "\n";
echo "OpenSSLCertificate=", (int) $opensslCert, "\n";

if ($curlLoaded && $curlHandle === false) {
    fwrite(STDERR, "FAIL: extension_loaded('curl') true but CurlHandle missing\n");
    ++$fail;
}
if (!$curlLoaded && $curlHandle) {
    fwrite(STDERR, "FAIL: CurlHandle visible but extension_loaded('curl') false\n");
    ++$fail;
}
if ($opensslLoaded && $opensslCert === false) {
    fwrite(STDERR, "FAIL: extension_loaded('openssl') true but OpenSSLCertificate missing\n");
    ++$fail;
}
if (!$opensslLoaded && $opensslCert) {
    fwrite(STDERR, "FAIL: OpenSSLCertificate visible but extension_loaded('openssl') false\n");
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
