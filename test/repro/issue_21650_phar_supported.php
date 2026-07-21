<?php
declare(strict_types=1);

echo 'getSupportedCompression ', method_exists(Phar::class, 'getSupportedCompression') ? 'Y' : 'N', "\n";
echo 'getSupportedSignatures ', method_exists(Phar::class, 'getSupportedSignatures') ? 'Y' : 'N', "\n";

$comp = Phar::getSupportedCompression();
echo 'comp_is_array ', is_array($comp) ? 'Y' : 'N', "\n";
echo 'has_GZ ', in_array('GZ', $comp, true) === Phar::canCompress(Phar::GZ) ? 'Y' : 'N', "\n";
echo 'has_BZ2 ', in_array('BZ2', $comp, true) === Phar::canCompress(Phar::BZ2) ? 'Y' : 'N', "\n";
echo 'comp_count ', count($comp), "\n";

$sig = Phar::getSupportedSignatures();
echo 'sig_is_array ', is_array($sig) ? 'Y' : 'N', "\n";
foreach (['MD5', 'SHA-1', 'SHA-256', 'SHA-512'] as $need) {
    echo 'sig_', str_replace(['-', ' '], '_', $need), ' ', in_array($need, $sig, true) ? 'Y' : 'N', "\n";
}
$wantOpenSsl = extension_loaded('openssl');
echo 'sig_OpenSSL ', in_array('OpenSSL', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
echo 'sig_OpenSSL_SHA256 ', in_array('OpenSSL_SHA256', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
echo 'sig_OpenSSL_SHA512 ', in_array('OpenSSL_SHA512', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
echo 'sig_count ', count($sig), "\n";
