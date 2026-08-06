<?php
/**
 * Issue #27816 — imap_get_quota / imap_get_quotaroot / imap_set_quota.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27816_imap_quota.php
 */
declare(strict_types=1);

foreach (['imap_get_quota', 'imap_get_quotaroot', 'imap_set_quota', 'imap_errors'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27816_repro_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);
$inboxReal = realpath($inbox);

$mbox = imap_open($inbox, '', '');
echo false === @imap_get_quota($mbox, $inbox) ? 'unset' : 'set';
echo "\n";
echo imap_set_quota($mbox, $inbox, 1024) ? 'setok' : 'setfail';
echo "\n";
$q = imap_get_quota($mbox, $inbox);
$st = is_array($q) ? $q['STORAGE'] : null;
echo is_array($q) && is_array($st)
    && isset($q['limit']) && isset($q['usage']) && isset($st['limit']) && isset($st['usage'])
    && 1024 === $q['limit'] && 1024 === $st['limit']
    && $q['usage'] === $st['usage'] && $q['usage'] >= 0
    ? 'getq' : 'nogetq';
echo "\n";
$qr = imap_get_quotaroot($mbox, $inbox);
echo is_array($qr) && isset($qr['limit']) && 1024 === $qr['limit'] ? 'getroot' : 'nogetroot';
echo "\n";
echo false === @imap_get_quota($mbox, $dir.'/NOSUCH') ? 'miss' : 'hit';
echo "\n";

imap_close($mbox);
try {
    imap_set_quota($mbox, $inbox, 10);
    echo "closed=ok\n";
} catch (TypeError $e) {
    echo "closed=typeerror\n";
}

@unlink($inbox);
@rmdir($dir);
