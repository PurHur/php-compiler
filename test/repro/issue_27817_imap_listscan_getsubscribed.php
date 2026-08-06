<?php
/**
 * Issue #27817 — imap_listscan/scan/scanmailbox + imap_getsubscribed.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27817_imap_listscan_getsubscribed.php
 */
declare(strict_types=1);

foreach (['imap_scan', 'imap_listscan', 'imap_scanmailbox', 'imap_getsubscribed', 'imap_open'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27817_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
$other = $dir.'/OTHER';
$empty = $dir.'/EMPTY';
copy($fixture, $inbox);
copy($fixture, $other);
file_put_contents($empty, "From empty@example.com Sat Aug 01 02:00:00 2026\n\nno match here\n");

$mbox = imap_open($inbox, '', '');
$hit = imap_listscan($mbox, $dir.'/', '*', 'Hello imap');
$inboxReal = realpath($inbox);
$otherReal = realpath($other);
echo is_array($hit) && $inboxReal !== false && in_array($inboxReal, $hit, true) ? 'scan_hit' : 'scan_miss';
echo "\n";
echo is_array($hit) && $otherReal !== false && in_array($otherReal, $hit, true) ? 'scan_other' : 'scan_no_other';
echo "\n";
$alias = imap_scan($mbox, $dir.'/', '*', 'Hello imap');
echo is_array($alias) && $hit === $alias ? 'alias_scan' : 'alias_scan_bad';
echo "\n";
$alias2 = imap_scanmailbox($mbox, $dir.'/', '*', 'Hello imap');
echo is_array($alias2) && $hit === $alias2 ? 'alias_mb' : 'alias_mb_bad';
echo "\n";
$miss = imap_listscan($mbox, $dir.'/', '*', 'definitely-not-present-xyz');
echo false === $miss ? 'scan_empty' : 'scan_not_empty';
echo "\n";

echo imap_subscribe($mbox, $other) ? 'sub' : 'nosub';
echo "\n";
$subs = imap_getsubscribed($mbox, $dir.'/', '*');
echo is_array($subs) && isset($subs[0]->name) && $subs[0]->name === $otherReal ? 'getsub' : 'nogetsub';
echo "\n";
echo false === imap_getsubscribed($mbox, $dir.'/', 'NOSUCH') ? 'getsub_miss' : 'getsub_hit';
echo "\n";

imap_close($mbox);
try {
    imap_listscan($mbox, $dir.'/', '*', 'x');
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}

@unlink($inbox);
@unlink($other);
@unlink($empty);
@rmdir($dir);
