<?php
/**
 * Issue #27800 — imap flags/alerts/gc/thread/acl/headers/mailboxmsginfo.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27800_imap_flags_meta.php
 */
declare(strict_types=1);

foreach ([
    'imap_setflag_full','imap_clearflag_full','imap_alerts','imap_gc','imap_thread',
    'imap_getacl','imap_setacl','imap_headers','imap_mailboxmsginfo','imap_errors',
] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo 'IMAP_GC_ELT=', defined('IMAP_GC_ELT') ? 'yes' : 'no', "\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27800_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
var_export(imap_setflag_full($mbox, '1', '\\Seen'));
echo "\n";
$ov = imap_fetch_overview($mbox, '1');
echo is_array($ov) ? ('seen='.$ov[0]->seen) : 'ov=false';
echo "\n";
imap_clearflag_full($mbox, '1', '\\Seen');
$info = imap_mailboxmsginfo($mbox);
echo is_object($info) ? ('Nmsgs='.$info->Nmsgs.';Unread='.$info->Unread) : 'info=false';
echo "\n";
$hdrs = imap_headers($mbox);
echo is_array($hdrs) ? ('headers='.count($hdrs)) : 'headers=false';
echo "\n";
var_export(imap_gc($mbox, IMAP_GC_ELT));
echo "\n";
$th = imap_thread($mbox);
echo is_array($th) ? ('thread='.(isset($th['0.num']) ? $th['0.num'] : 'nokey')) : 'thread=false';
echo "\n";
var_export(imap_setacl($mbox, $inbox, 'alice', 'lrswipkxtecda'));
echo "\n";
$acl = imap_getacl($mbox, $inbox);
echo is_array($acl) ? ('acl='.$acl['alice']) : 'acl=false';
echo "\n";
var_export(imap_alerts());
echo "\n";

imap_close($mbox);
try {
    imap_gc($mbox, IMAP_GC_ELT);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}
@unlink($inbox);
@rmdir($dir);
