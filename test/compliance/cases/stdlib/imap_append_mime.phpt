--TEST--
stdlib imap_append/savebody/bodystruct/fetchmime local mbox (#27814)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27814)');
}
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_append','imap_savebody','imap_bodystruct','imap_fetchmime'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27814_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";
echo imap_num_msg($mbox), "\n";

$msg = "From: append@example.com\nTo: you@example.com\nSubject: Appended\nContent-Type: text/plain\n\nappended body\n";
echo imap_append($mbox, $inbox, $msg) ? 'append' : 'noappend';
echo "\n";
echo imap_num_msg($mbox), "\n";

$out = $dir.'/body.txt';
echo imap_savebody($mbox, $out, 3, '1') ? 'save' : 'nosave';
echo "\n";
echo trim((string) file_get_contents($out)), "\n";

$struct = imap_bodystruct($mbox, 3, '1');
echo is_object($struct) ? $struct->subtype : 'nostruct';
echo "\n";
echo is_object($struct) ? (string) $struct->bytes : '0';
echo "\n";

$mime = imap_fetchmime($mbox, 3, '1');
echo is_string($mime) && str_contains($mime, 'Subject: Appended') ? 'mime' : 'nomime';
echo "\n";

// Bad mailbox folder → false, no crash
echo @imap_append($mbox, '{127.0.0.1:1/imap}INBOX', $msg) ? 'badok' : 'badfail';
echo "\n";

imap_close($mbox);
try {
    imap_append($mbox, $inbox, $msg);
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($out);
@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
1111
open
2
append
3
save
appended body
PLAIN
14
mime
badfail
closed
