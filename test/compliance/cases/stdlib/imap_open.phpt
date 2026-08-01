--TEST--
stdlib imap_open local mbox + remote fail (#3663)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#3663)');
}
--FILE--
<?php
declare(strict_types=1);

echo function_exists('imap_open') ? '1' : '0';
echo function_exists('imap_search') ? '1' : '0';
echo function_exists('imap_fetchbody') ? '1' : '0';
echo extension_loaded('imap') ? '1' : '0';
echo class_exists('IMAP\\Connection') ? '1' : '0';
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$mbox = imap_open($fixture, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";
echo imap_num_msg($mbox), "\n";
$hits = imap_search($mbox, 'SUBJECT "Hello"');
echo count($hits), ':', $hits[0], "\n";
$h = imap_headerinfo($mbox, 1);
echo $h->subject, "\n";
echo trim(imap_fetchbody($mbox, 1, '1')), "\n";
imap_close($mbox);

$bad = @imap_open('{127.0.0.1:1/imap}INBOX', 'u', 'p');
echo false === $bad ? 'fail' : 'ok';
echo "\n";
$err = imap_last_error();
echo is_string($err) ? 'last' : 'nolast';
echo "\n";
?>
--EXPECT--
11111
open
2
1:1
Hello imap
hello imap body
fail
last
