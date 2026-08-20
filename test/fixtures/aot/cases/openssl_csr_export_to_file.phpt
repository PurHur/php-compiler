--TEST--
AOT: openssl_csr_export_to_file() PEM + invalid PEM false (#32697 leftover of #6421, ext/openssl/xp.c)
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

$file = '/tmp/phpc_csr_export_to_file_32697.pem';
var_export(openssl_csr_export_to_file($pem, $file));
echo '|';
$written = @file_get_contents($file);
echo (\is_string($written) && str_contains($written, 'BEGIN CERTIFICATE REQUEST') && str_contains($written, 'END CERTIFICATE REQUEST'))
    ? 'file-ok'
    : 'file-bad';
echo '|';
var_export(openssl_csr_export_to_file('not-a-csr', $file));
@unlink($file);
echo "\n";
--EXPECT--
true|file-ok|false
