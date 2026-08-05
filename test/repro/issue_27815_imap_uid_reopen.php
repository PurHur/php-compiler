<?php
/**
 * Issue #27815 — imap_uid/msgno/num_recent/reopen.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27815_imap_uid_reopen.php
 */
declare(strict_types=1);

foreach (['imap_num_msg','imap_uid','imap_msgno','imap_num_recent','imap_reopen'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27815_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
$other = $dir.'/OTHER';
copy($fixture, $inbox);
copy($fixture, $other);

$mbox = imap_open($inbox, '', '');
echo 'num=', imap_num_msg($mbox), "\n";
$uid = imap_uid($mbox, 2);
echo 'uid=', var_export($uid, true), "\n";
echo 'msgno=', imap_msgno($mbox, (int) $uid), "\n";
echo 'recent=', imap_num_recent($mbox), "\n";
echo 'baduid=', var_export(imap_uid($mbox, 99), true), "\n";
echo 'badmsgno=', imap_msgno($mbox, 99), "\n";
var_export(imap_reopen($mbox, $other));
echo "\n";
echo 'num2=', imap_num_msg($mbox), "\n";
var_export(@imap_reopen($mbox, $dir.'/missing'));
echo "\n";

imap_close($mbox);
try {
    imap_uid($mbox, 1);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}
@unlink($inbox);
@unlink($other);
@rmdir($dir);
