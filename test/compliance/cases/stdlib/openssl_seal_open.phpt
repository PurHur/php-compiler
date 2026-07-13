--TEST--
openssl_seal()/openssl_open() envelope round-trip (#6523, ext/openssl/openssl.c)
--FILE--
<?php
if (!function_exists('openssl_seal') || !function_exists('openssl_open')) {
    echo "missing\n";
    exit(1);
}

$privateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8wggE7AgEAAkEAynon2j7jamlYfawS
ka1KJchd8IiqeSQm1TtkQljKoGamidfsV9sIUNbEMisGaAmPhuZPshz2G5J/dnUA
VBoNnwIDAQABAkEAtm0fEPTOYyatEvWA+X2/K5F+ieQoa+MVldLf/yMO1Tps9oBj
nWlMBWYowCYS5Bthnh8TdyJ7GFxnk6jldJueeQIhAOmxSEZPAcVJ2PBPmyQY6jr3
SePP0KPRXMcvWNrVhUeTAiEA3c4RT4zf5cwiy8xD4ugWXH4pbpWHdh6P2NrLGhb/
EUUCIEOcgA2fdCKxT+uPDJKwBqySuTUI/hM3UoFqaGm/1vSzAiA4lYxm/epUhmpO
EXM0HL8vo2PQeUcQhCVwTgjIRBuX/QIhAIeiJ3Cx6qWS+S+0fGnWxtpniKbQrwnS
l08JY/sj5yCM
-----END PRIVATE KEY-----
PEM;

$publicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBAMp6J9o+42ppWH2sEpGtSiXIXfCIqnkk
JtU7ZEJYyqBmponX7FfbCFDWxDIrBmgJj4bmT7Ic9huSf3Z1AFQaDZ8CAwEAAQ==
-----END PUBLIC KEY-----
PEM;

$iv = random_bytes(16);
$len = openssl_seal('secret', $sealed, $ekeys, [$publicKey], 'AES-128-CBC', $iv);
if (false === $len || !is_array($ekeys) || 1 !== count($ekeys)) {
    echo "seal-fail\n";
    exit(1);
}

$opened = openssl_open($sealed, $plain, $ekeys[0], $privateKey, 'AES-128-CBC', $iv);
echo ($opened && 'secret' === $plain) ? "roundtrip-ok\n" : "open-fail\n";

echo function_exists('openssl_seal') && function_exists('openssl_open') ? "registered-ok\n" : "registered-fail\n";
?>
--EXPECT--
roundtrip-ok
registered-ok
