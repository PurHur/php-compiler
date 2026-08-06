<?php
/**
 * Issue #27765 — imap_mail_compose MIME from envelope+body.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27765_imap_mail_compose.php
 */
declare(strict_types=1);

echo 'imap=', extension_loaded('imap') ? 'yes' : 'no', "\n";
echo 'compose=', function_exists('imap_mail_compose') ? 'yes' : 'no', "\n";
echo 'TYPETEXT=', defined('TYPETEXT') ? (string) TYPETEXT : 'undef', "\n";
echo 'TYPEMULTIPART=', defined('TYPEMULTIPART') ? (string) TYPEMULTIPART : 'undef', "\n";

$rf = new ReflectionFunction('imap_mail_compose');
echo 'params=', $rf->getParameters()[0]->getName(), ',', $rf->getParameters()[1]->getName(), "\n";
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";

$envelope = ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'Hi'];
$body = [['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'hello']];
$msg = imap_mail_compose($envelope, $body);
echo is_string($msg) && str_contains($msg, 'hello') && str_contains($msg, 'Subject: Hi') ? "text_ok\n" : "text_bad\n";

// Zend requires ≥2 nested parts for TYPEMULTIPART
$multi = [
    ['type' => TYPEMULTIPART, 'boundary' => 'b'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'one'],
];
$bad = @imap_mail_compose($envelope, $multi);
echo (false === $bad) ? "multi_one_false\n" : "multi_one_bad\n";

$multi2 = [
    ['type' => TYPEMULTIPART, 'boundary' => 'bound42'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'part-a'],
    ['type' => TYPETEXT, 'subtype' => 'plain', 'contents.data' => 'part-b'],
];
$msg2 = imap_mail_compose($envelope, $multi2);
echo is_string($msg2) && str_contains($msg2, 'part-a') && str_contains($msg2, '--bound42--') ? "multi_ok\n" : "multi_bad\n";
