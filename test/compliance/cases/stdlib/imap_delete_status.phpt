--TEST--
stdlib imap_delete/undelete/expunge + ping/check/status (#27783)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27783)');
}
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_delete','imap_undelete','imap_expunge','imap_ping','imap_check','imap_status'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";
echo defined('SA_ALL') ? '1' : '0';
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27783_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo imap_ping($mbox) ? 'ping' : 'noping';
echo "\n";
echo imap_num_msg($mbox), "\n";

$chk = imap_check($mbox);
echo is_object($chk) && (int)$chk->Nmsgs === 2 ? 'check' : 'nocheck';
echo "\n";

$st = imap_status($mbox, $inbox, SA_ALL);
echo is_object($st) && (int)$st->messages === 2 ? 'status' : 'nostatus';
echo "\n";

echo imap_delete($mbox, '2') ? 'del' : 'nodel';
echo "\n";
echo imap_undelete($mbox, '2') ? 'undel' : 'noundel';
echo "\n";
echo imap_delete($mbox, '2') ? 'del2' : 'nodel2';
echo "\n";
echo imap_expunge($mbox) ? 'exp' : 'noexp';
echo "\n";
echo imap_num_msg($mbox), "\n";

imap_close($mbox);
try {
    imap_ping($mbox);
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
111111
1
ping
2
check
status
del
undel
del2
exp
1
closed
