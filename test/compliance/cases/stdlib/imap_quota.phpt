--TEST--
stdlib imap_get_quota/get_quotaroot/set_quota local mbox (#27816)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27816)');
}
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_get_quota', 'imap_get_quotaroot', 'imap_set_quota'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27816_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";
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
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
111
open
unset
setok
getq
getroot
miss
closed
