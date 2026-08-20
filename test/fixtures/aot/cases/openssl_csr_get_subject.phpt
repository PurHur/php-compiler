--TEST--
AOT: openssl_csr_get_subject() PEM CN/C + invalid PEM false (#32692 leftover of #6421, ext/openssl/xp.c)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslCsrNative.php';
if (!\PHPCompiler\ext\openssl\VmOpensslCsrNative::available()) {
    echo 'skip';
}
?>
--FILE--
<?php
declare(strict_types=1);

$pem = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIHWMIGBAgEAMBwxCzAJBgNVBAYTAlVTMQ0wCwYDVQQDDAR0ZXN0MFwwDQYJKoZI
hvcNAQEBBQADSwAwSAJBAJvvRzuZyqMSXp+b1ue/y+w5CCj/LLvsH5hBh0lxVOL1
iKowgGV0P/wH22UnOIvpJ7NvIXMu7JexpaqdHln2UEkCAwEAAaAAMA0GCSqGSIb3
DQEBCwUAA0EAXPcbsOj7qZsACzUrR7B0sWkNxUtANNvdwF9UIBu8n+Mkz5mWvmN/
xHd8PFTBAcitzxQQwDAI1Vj3EUW7Qn3lIw==
-----END CERTIFICATE REQUEST-----
PEM;

$lit = openssl_csr_get_subject($pem);
echo is_array($lit) ? '1' : '0', "\n";
echo $lit['CN'] ?? '', "\n";
echo $lit['C'] ?? '', "\n";
echo openssl_csr_get_subject($pem, true)['CN'] ?? '', "\n";
var_export(openssl_csr_get_subject('not-a-csr'));
echo "\n";
--EXPECT--
1
test
US
test
false
