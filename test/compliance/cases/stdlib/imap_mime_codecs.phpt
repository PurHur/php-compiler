--TEST--
stdlib imap MIME transfer codecs (#27683)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27683)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('imap_base64') ? '1' : '0';
echo function_exists('imap_qprint') ? '1' : '0';
echo function_exists('imap_8bit') ? '1' : '0';
echo function_exists('imap_binary') ? '1' : '0';
echo function_exists('imap_utf8') ? '1' : '0';
echo function_exists('imap_mime_header_decode') ? '1' : '0';
echo "\n";

echo imap_base64(imap_binary('hi')), "\n";
echo var_export(imap_base64('!!!'), true), "\n";
echo imap_qprint(imap_8bit('x')), "\n";
echo imap_utf8('=?UTF-8?B?SGVsbG8=?='), "\n";

$parts = imap_mime_header_decode('=?UTF-8?B?SGVsbG8=?= world');
echo count($parts), ':', $parts[0]->charset, ':', $parts[0]->text, ':', $parts[1]->charset, ':', $parts[1]->text, "\n";

$rf = new ReflectionFunction('imap_utf8');
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";
$rf2 = new ReflectionFunction('imap_mime_header_decode');
echo $rf2->hasReturnType() ? (string)$rf2->getReturnType() : '-', "\n";
?>
--EXPECT--
111111
hi
false
x
Hello
2:UTF-8:Hello:default: world
mime_encoded_text
string
array|false
