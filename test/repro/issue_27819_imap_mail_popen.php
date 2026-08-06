<?php
/**
 * Issue #27819 — imap_mail / imap_popen.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27819_imap_mail_popen.php
 */
declare(strict_types=1);

echo 'imap=', extension_loaded('imap') ? 'yes' : 'no', "\n";
echo 'mail=', function_exists('imap_mail') ? 'yes' : 'no', "\n";
echo 'popen=', function_exists('imap_popen') ? 'yes' : 'no', "\n";

try {
    imap_mail('', 's', 'm');
    echo "empty_to=ok\n";
} catch (ValueError $e) {
    echo "empty_to=valueerror\n";
}
try {
    imap_mail('a@b.c', '', 'm');
    echo "empty_subj=ok\n";
} catch (ValueError $e) {
    echo "empty_subj=valueerror\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27819_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_popen($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'popen' : 'nopopen';
echo "\n";
echo imap_num_msg($mbox), "\n";
imap_close($mbox);

// Soft-fail without a working sendmail — must not crash.
$sent = @imap_mail('you@example.com', 'Hello', 'body');
echo is_bool($sent) ? 'mail_bool' : 'mail_bad';
echo "\n";
