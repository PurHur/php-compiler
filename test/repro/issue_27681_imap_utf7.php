<?php
/**
 * Issue #27681 — imap_utf7_encode / imap_utf7_decode Modified UTF-7.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27681_imap_utf7.php
 */
declare(strict_types=1);

echo 'imap=', extension_loaded('imap') ? 'yes' : 'no', "\n";
echo 'encode=', function_exists('imap_utf7_encode') ? 'yes' : 'no', "\n";
echo 'decode=', function_exists('imap_utf7_decode') ? 'yes' : 'no', "\n";

$rf = new ReflectionFunction('imap_utf7_encode');
echo 'enc_param=', $rf->getParameters()[0]->getName(), "\n";
echo 'enc_return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
$rf2 = new ReflectionFunction('imap_utf7_decode');
echo 'dec_param=', $rf2->getParameters()[0]->getName(), "\n";
echo 'dec_return=', $rf2->hasReturnType() ? (string) $rf2->getReturnType() : '-', "\n";

$latin1 = "R\xe9sum\xe9";
$enc = imap_utf7_encode($latin1);
echo 'enc=', $enc, "\n";
$dec = imap_utf7_decode($enc);
echo 'round=', ($dec === $latin1) ? 'ok' : 'bad', "\n";
echo 'amp=', imap_utf7_encode('A&B'), "\n";
echo 'named=', imap_utf7_encode(string: 'Test'), "\n";
echo 'jp=', (false === imap_utf7_decode('&ZeVnLIqe-')) ? 'false' : 'ok', "\n";
