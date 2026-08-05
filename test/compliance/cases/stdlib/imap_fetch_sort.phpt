--TEST--
stdlib imap_fetchstructure/fetchheader/fetch_overview/body/sort local mbox (#27784)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27784)');
}
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_fetchstructure','imap_fetchheader','imap_fetch_overview','imap_body','imap_sort'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";
echo defined('SORTSUBJECT') ? '1' : '0';
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27784_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";

$st = imap_fetchstructure($mbox, 1);
echo is_object($st) ? $st->subtype : 'nostruct';
echo "\n";
echo is_object($st) ? (string) $st->bytes : '0';
echo "\n";

$hdr = imap_fetchheader($mbox, 1);
echo is_string($hdr) && str_contains($hdr, 'Subject: Hello imap') ? 'header' : 'noheader';
echo "\n";

$ov = imap_fetch_overview($mbox, '1,2');
echo is_array($ov) ? (string) count($ov) : '0';
echo "\n";
echo is_array($ov) ? $ov[1]->subject : 'nosubj';
echo "\n";

$body = imap_body($mbox, 2);
echo is_string($body) ? trim($body) : 'nobody';
echo "\n";

$sorted = imap_sort($mbox, SORTSUBJECT, 0);
echo is_array($sorted) ? implode(',', $sorted) : 'nosort';
echo "\n";
$rev = imap_sort($mbox, SORTSUBJECT, 1);
echo is_array($rev) ? implode(',', $rev) : 'norev';
echo "\n";

// Bad msgno → false, no crash
echo @imap_fetchstructure($mbox, 99) ? 'badok' : 'badfail';
echo "\n";

imap_close($mbox);
try {
    imap_body($mbox, 1);
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
11111
1
open
PLAIN
16
header
2
Second message
second body
1,2
2,1
badfail
closed
