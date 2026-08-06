--TEST--
stdlib imap_utf8_to_mutf7 / imap_mutf7_to_utf8 Modified UTF-7 (#27764)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27764)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('imap') ? '1' : '0';
echo function_exists('imap_utf8_to_mutf7') ? '1' : '0';
echo function_exists('imap_mutf7_to_utf8') ? '1' : '0';
echo "\n";

$rf = new ReflectionFunction('imap_utf8_to_mutf7');
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";
$rf2 = new ReflectionFunction('imap_mutf7_to_utf8');
echo $rf2->getParameters()[0]->getName(), "\n";
echo $rf2->hasReturnType() ? (string)$rf2->getReturnType() : '-', "\n";

echo imap_utf8_to_mutf7(''), "\n";
echo imap_utf8_to_mutf7('Test'), "\n";
echo imap_utf8_to_mutf7('täst'), "\n";
echo imap_mutf7_to_utf8('t&AOQ-st'), "\n";
echo (imap_mutf7_to_utf8(imap_utf8_to_mutf7('你好')) === '你好') ? "cjk_ok\n" : "cjk_bad\n";
echo imap_utf8_to_mutf7(string: '&'), "\n";
echo imap_mutf7_to_utf8(string: '&-'), "\n";
?>
--EXPECT--
111
string
string|false
string
string|false

Test
t&AOQ-st
täst
cjk_ok
&-
&
