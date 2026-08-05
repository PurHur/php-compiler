--TEST--
stdlib imap_uid/msgno/num_recent/reopen local mbox (#27815)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27815)');
}
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_uid','imap_msgno','imap_num_recent','imap_reopen'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27815_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
$other = $dir.'/OTHER';
copy($fixture, $inbox);
copy($fixture, $other);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";
echo imap_num_msg($mbox), "\n";
$uid = imap_uid($mbox, 1);
echo false === $uid ? 'nouid' : (string) $uid;
echo "\n";
echo imap_msgno($mbox, (int) $uid), "\n";
echo imap_num_recent($mbox), "\n";
echo false === imap_uid($mbox, 99) ? 'baduid' : 'okuid';
echo "\n";
echo 0 === imap_msgno($mbox, 99) ? 'badmsg' : 'okmsg';
echo "\n";
echo imap_reopen($mbox, $other) ? 'reopen' : 'noreopen';
echo "\n";
echo imap_num_msg($mbox), "\n";
echo @imap_reopen($mbox, $dir.'/missing') ? 'missok' : 'missfail';
echo "\n";

imap_close($mbox);
try {
    imap_uid($mbox, 1);
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@unlink($other);
@rmdir($dir);
?>
--EXPECT--
1111
open
2
1
1
0
baduid
badmsg
reopen
2
missfail
closed
