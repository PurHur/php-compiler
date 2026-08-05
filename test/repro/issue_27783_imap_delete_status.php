<?php
/**
 * Issue #27783 — imap_delete/undelete/expunge + ping/check/status.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27783_imap_delete_status.php
 */
declare(strict_types=1);

foreach (['imap_delete','imap_undelete','imap_expunge','imap_ping','imap_check','imap_status'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo 'SA_ALL=', defined('SA_ALL') ? 'yes' : 'no', "\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27783_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
var_export(imap_ping($mbox));
echo "\n";
$chk = imap_check($mbox);
echo is_object($chk) ? ('Nmsgs='.$chk->Nmsgs) : 'check=false';
echo "\n";
imap_delete($mbox, '2');
imap_expunge($mbox);
echo 'num=', imap_num_msg($mbox), "\n";
imap_close($mbox);
try {
    imap_ping($mbox);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}
@unlink($inbox);
@rmdir($dir);
