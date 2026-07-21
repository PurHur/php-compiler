--TEST--
stdlib Phar getSupportedCompression/Signatures (#21650)
--FILE--
<?php
declare(strict_types=1);

echo 'getSupportedCompression ', method_exists(Phar::class, 'getSupportedCompression') ? 'Y' : 'N', "\n";
echo 'getSupportedSignatures ', method_exists(Phar::class, 'getSupportedSignatures') ? 'Y' : 'N', "\n";

$comp = Phar::getSupportedCompression();
echo 'comp_is_array ', is_array($comp) ? 'Y' : 'N', "\n";
echo 'has_GZ ', in_array('GZ', $comp, true) === Phar::canCompress(Phar::GZ) ? 'Y' : 'N', "\n";
echo 'has_BZ2 ', in_array('BZ2', $comp, true) === Phar::canCompress(Phar::BZ2) ? 'Y' : 'N', "\n";

$sig = Phar::getSupportedSignatures();
echo 'sig_is_array ', is_array($sig) ? 'Y' : 'N', "\n";
echo 'sig_MD5 ', in_array('MD5', $sig, true) ? 'Y' : 'N', "\n";
echo 'sig_SHA1 ', in_array('SHA-1', $sig, true) ? 'Y' : 'N', "\n";
echo 'sig_SHA256 ', in_array('SHA-256', $sig, true) ? 'Y' : 'N', "\n";
echo 'sig_SHA512 ', in_array('SHA-512', $sig, true) ? 'Y' : 'N', "\n";
$wantOpenSsl = extension_loaded('openssl');
echo 'sig_OpenSSL ', in_array('OpenSSL', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
echo 'sig_OpenSSL_SHA256 ', in_array('OpenSSL_SHA256', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
echo 'sig_OpenSSL_SHA512 ', in_array('OpenSSL_SHA512', $sig, true) === $wantOpenSsl ? 'Y' : 'N', "\n";
--EXPECT--
getSupportedCompression Y
getSupportedSignatures Y
comp_is_array Y
has_GZ Y
has_BZ2 Y
sig_is_array Y
sig_MD5 Y
sig_SHA1 Y
sig_SHA256 Y
sig_SHA512 Y
sig_OpenSSL Y
sig_OpenSSL_SHA256 Y
sig_OpenSSL_SHA512 Y
