<?php
/**
 * Issue #27799 — imap mailbox list/subscribe/create when IMAP advertised.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27799_imap_mailbox_mgmt.php
 */
declare(strict_types=1);

$fns = [
    'imap_list',
    'imap_lsub',
    'imap_subscribe',
    'imap_unsubscribe',
    'imap_createmailbox',
    'imap_deletemailbox',
    'imap_renamemailbox',
    'imap_getmailboxes',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27799_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
$created = $dir.'/Sent';
var_export(imap_createmailbox($mbox, $created));
echo "\n";
$list = imap_list($mbox, $dir.'/', '*');
echo is_array($list) ? ('list='.count($list)) : 'list=false';
echo "\n";
var_export(imap_subscribe($mbox, $created));
echo "\n";
$lsub = imap_lsub($mbox, $dir.'/', '*');
echo is_array($lsub) ? ('lsub='.count($lsub)) : 'lsub=false';
echo "\n";

imap_close($mbox);
try {
    imap_list($mbox, $dir.'/', '*');
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}

@unlink($created);
@unlink($inbox);
@rmdir($dir);
