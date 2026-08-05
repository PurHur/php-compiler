<?php
/**
 * Issue #27784 — imap_fetchstructure/fetchheader/fetch_overview/body/sort.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27784_imap_fetch_sort.php
 */
declare(strict_types=1);

foreach (['imap_fetchstructure','imap_fetchheader','imap_fetch_overview','imap_body','imap_sort','imap_fetchbody'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo 'SORTSUBJECT=', defined('SORTSUBJECT') ? 'yes' : 'no', "\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27784_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
$st = imap_fetchstructure($mbox, 1);
echo is_object($st) ? ('subtype='.$st->subtype) : 'struct=false';
echo "\n";
$hdr = imap_fetchheader($mbox, 1);
echo is_string($hdr) && str_contains($hdr, 'Subject: Hello imap') ? 'header=ok' : 'header=bad';
echo "\n";
$ov = imap_fetch_overview($mbox, '1:2');
echo is_array($ov) ? ('overview='.count($ov).':'.$ov[0]->subject) : 'overview=false';
echo "\n";
$body = imap_body($mbox, 2);
echo is_string($body) ? ('body='.trim($body)) : 'body=false';
echo "\n";
$sorted = imap_sort($mbox, SORTSUBJECT, 0);
echo is_array($sorted) ? ('sort='.implode(',', $sorted)) : 'sort=false';
echo "\n";
$rev = imap_sort($mbox, SORTSUBJECT, 1);
echo is_array($rev) ? ('rev='.implode(',', $rev)) : 'rev=false';
echo "\n";

imap_close($mbox);
try {
    imap_body($mbox, 1);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}
@unlink($inbox);
@rmdir($dir);
