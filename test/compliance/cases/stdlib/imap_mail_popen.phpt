--TEST--
stdlib imap_mail + imap_popen (#27819)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27819)');
}
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('imap') ? '1' : '0';
echo function_exists('imap_mail') ? '1' : '0';
echo function_exists('imap_popen') ? '1' : '0';
echo "\n";

try {
    imap_mail('', 's', 'm');
    echo "empty_to=ok\n";
} catch (ValueError $e) {
    echo "empty_to=ve\n";
}
try {
    imap_mail('a@b.c', '', 'm');
    echo "empty_subj=ok\n";
} catch (ValueError $e) {
    echo "empty_subj=ve\n";
}

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27819_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_popen($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'popen' : 'nopopen';
echo "\n";
echo imap_num_msg($mbox), "\n";
imap_close($mbox);

$sent = @imap_mail('you@example.com', 'Hello', 'body');
echo is_bool($sent) ? 'mail_bool' : 'mail_bad';
echo "\n";

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
111
empty_to=ve
empty_subj=ve
popen
2
mail_bool
