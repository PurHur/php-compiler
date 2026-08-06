--TEST--
stdlib imap_mail_compose MIME envelope+body (#27765)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27765)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('imap') ? '1' : '0';
echo function_exists('imap_mail_compose') ? '1' : '0';
echo defined('TYPETEXT') ? '1' : '0';
echo defined('TYPEMULTIPART') ? '1' : '0';
echo "\n";

$rf = new ReflectionFunction('imap_mail_compose');
echo $rf->getParameters()[0]->getName(), ',', $rf->getParameters()[1]->getName(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";

$envelope = ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'Hi'];
$body = [['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'hello']];
$msg = imap_mail_compose($envelope, $body);
echo (is_string($msg) && str_contains($msg, 'hello') && str_contains($msg, 'Subject: Hi')) ? "text_ok\n" : "text_bad\n";

$multi = [
    ['type' => TYPEMULTIPART, 'boundary' => 'b'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'one'],
];
echo (false === @imap_mail_compose($envelope, $multi)) ? "multi_one_false\n" : "multi_one_bad\n";

$multi2 = [
    ['type' => TYPEMULTIPART, 'boundary' => 'bound42'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'part-a'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'part-b'],
];
$msg2 = imap_mail_compose($envelope, $multi2);
echo (is_string($msg2) && str_contains($msg2, 'part-a') && str_contains($msg2, '--bound42--')) ? "multi_ok\n" : "multi_bad\n";

try {
    imap_mail_compose($envelope, []);
    echo "empty_ok\n";
} catch (ValueError $e) {
    echo "empty_ve\n";
}
?>
--EXPECT--
1111
envelope,bodies
string|false
text_ok
multi_one_false
multi_ok
empty_ve
