--TEST--
stdlib imap flags/alerts/gc/thread/acl/headers/mailboxmsginfo (#27800)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27800)');
}
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'imap_setflag_full','imap_clearflag_full','imap_alerts','imap_gc','imap_thread',
    'imap_getacl','imap_setacl','imap_headers','imap_mailboxmsginfo',
] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";
echo defined('IMAP_GC_ELT') ? '1' : '0';
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27800_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";

echo imap_setflag_full($mbox, '1', '\\Seen') ? 'set' : 'noset';
echo "\n";
$ov = imap_fetch_overview($mbox, '1');
echo is_array($ov) ? (string) $ov[0]->seen : '0';
echo "\n";
echo imap_clearflag_full($mbox, '1', '\\Seen') ? 'clear' : 'noclear';
echo "\n";

$info = imap_mailboxmsginfo($mbox);
echo is_object($info) ? (string) $info->Nmsgs : '0';
echo "\n";
echo is_object($info) ? (string) $info->Unread : '0';
echo "\n";

$hdrs = imap_headers($mbox);
echo is_array($hdrs) ? (string) count($hdrs) : '0';
echo "\n";

echo imap_gc($mbox, IMAP_GC_ELT) ? 'gc' : 'nogc';
echo "\n";
$th = imap_thread($mbox);
echo is_array($th) && isset($th['0.num']) ? (string) $th['0.num'] : '0';
echo "\n";

echo imap_setacl($mbox, $inbox, 'alice', 'lr') ? 'setacl' : 'nosetacl';
echo "\n";
$acl = imap_getacl($mbox, $inbox);
echo is_array($acl) ? $acl['alice'] : 'noacl';
echo "\n";

echo false === imap_alerts() ? 'noalerts' : 'alerts';
echo "\n";

imap_close($mbox);
try {
    imap_gc($mbox, IMAP_GC_ELT);
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
111111111
1
open
set
1
clear
2
2
2
gc
1
setacl
lr
noalerts
closed
