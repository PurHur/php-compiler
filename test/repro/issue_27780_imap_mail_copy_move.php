<?php
/**
 * Issue #27780 — imap_mail_copy / imap_mail_move.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27780_imap_mail_copy_move.php
 */
declare(strict_types=1);

echo 'loaded=', extension_loaded('imap') ? 'yes' : 'no', "\n";
echo 'copy=', function_exists('imap_mail_copy') ? 'yes' : 'no', "\n";
echo 'move=', function_exists('imap_mail_move') ? 'yes' : 'no', "\n";
$funcs = get_extension_funcs('imap') ?: [];
echo 'has_copy=', in_array('imap_mail_copy', $funcs, true) ? 'yes' : 'no', "\n";
echo 'has_move=', in_array('imap_mail_move', $funcs, true) ? 'yes' : 'no', "\n";
echo 'CP_UID=', defined('CP_UID') ? (string) CP_UID : 'undef', "\n";
echo 'CP_MOVE=', defined('CP_MOVE') ? (string) CP_MOVE : 'undef', "\n";

$rf = new ReflectionFunction('imap_mail_copy');
echo 'copy_params=';
foreach ($rf->getParameters() as $i => $p) {
    echo ($i ? ',' : ''), $p->getName();
}
echo "\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27780_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
$other = $dir.'/OTHER';
copy($fixture, $inbox);
touch($other);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no', "\n";
echo 'n=', imap_num_msg($mbox), "\n";
echo imap_mail_copy($mbox, '1', $other) ? 'copied' : 'nocopy', "\n";
$otherBox = imap_open($other, '', '');
echo 'other_n=', imap_num_msg($otherBox), "\n";
imap_close($otherBox);
echo imap_mail_move($mbox, '2', $other) ? 'moved' : 'nomove', "\n";
echo 'src_n=', imap_num_msg($mbox), "\n"; // still 2 until expunge
imap_expunge($mbox);
echo 'src_after=', imap_num_msg($mbox), "\n";
imap_close($mbox);

@unlink($inbox);
@unlink($other);
@rmdir($dir);
