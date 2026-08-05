<?php
/**
 * Issue #27814 — imap_append/savebody/bodystruct/fetchmime when IMAP advertised.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27814_imap_append_mime.php
 */
declare(strict_types=1);

foreach (['imap_open', 'imap_append', 'imap_savebody', 'imap_bodystruct', 'imap_fetchmime'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27814_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
$msg = "From: a@example.com\nSubject: X\nContent-Type: text/plain\n\nhello\n";
var_export(imap_append($mbox, $inbox, $msg));
echo "\n";
echo 'num=', imap_num_msg($mbox), "\n";
$out = $dir.'/b.txt';
var_export(imap_savebody($mbox, $out, 3, '1'));
echo "\n";
$st = imap_bodystruct($mbox, 3, '1');
echo is_object($st) ? ('subtype='.$st->subtype) : 'struct=false';
echo "\n";
$mime = imap_fetchmime($mbox, 3, '1');
echo is_string($mime) ? ('mime_len='.strlen($mime)) : 'mime=false';
echo "\n";

imap_close($mbox);
try {
    imap_append($mbox, $inbox, $msg);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}

@unlink($out);
@unlink($inbox);
@rmdir($dir);
