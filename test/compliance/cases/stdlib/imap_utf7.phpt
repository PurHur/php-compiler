--TEST--
stdlib imap_utf7_encode/decode Modified UTF-7 (#27681)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27681)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('imap') ? '1' : '0';
echo function_exists('imap_utf7_encode') ? '1' : '0';
echo function_exists('imap_utf7_decode') ? '1' : '0';
echo "\n";

$rf = new ReflectionFunction('imap_utf7_encode');
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";
$rf2 = new ReflectionFunction('imap_utf7_decode');
echo $rf2->getParameters()[0]->getName(), "\n";
echo $rf2->hasReturnType() ? (string)$rf2->getReturnType() : '-', "\n";

$latin1 = "R\xe9sum\xe9";
$enc = imap_utf7_encode($latin1);
echo $enc, "\n";
echo (imap_utf7_decode($enc) === $latin1) ? "round_ok\n" : "round_bad\n";
echo imap_utf7_encode('A&B'), "\n";
echo imap_utf7_encode(string: 'Test'), "\n";
echo (false === imap_utf7_decode('&ZeVnLIqe-')) ? "jp_false\n" : "jp_ok\n";
?>
--EXPECT--
111
string
string
string
string|false
R&AOk-sum&AOk-
round_ok
A&-B
Test
jp_false
