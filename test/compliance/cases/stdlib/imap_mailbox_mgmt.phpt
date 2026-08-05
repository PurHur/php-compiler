--TEST--
stdlib imap mailbox list/subscribe/create local mbox (#27799)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27799)');
}
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'imap_list',
    'imap_lsub',
    'imap_subscribe',
    'imap_unsubscribe',
    'imap_createmailbox',
    'imap_deletemailbox',
    'imap_renamemailbox',
    'imap_getmailboxes',
];
foreach ($fns as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27799_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no';
echo "\n";

$created = $dir.'/Archive';
echo imap_createmailbox($mbox, $created) ? 'create' : 'nocreate';
echo "\n";

$list = imap_list($mbox, $dir.'/', '*');
$createdReal = realpath($created);
echo is_array($list) && $createdReal !== false && in_array($createdReal, $list, true) ? 'listed' : 'nolist';
echo "\n";

$boxes = imap_getmailboxes($mbox, $dir.'/', 'Archive');
echo is_array($boxes) && isset($boxes[0]->name) && $boxes[0]->name === $createdReal ? 'getmb' : 'noget';
echo "\n";

echo imap_subscribe($mbox, $created) ? 'sub' : 'nosub';
echo "\n";
$lsub = imap_lsub($mbox, $dir.'/', '*');
echo is_array($lsub) && $createdReal !== false && in_array($createdReal, $lsub, true) ? 'lsub' : 'nolsub';
echo "\n";

$renamed = $dir.'/Archive2';
echo imap_renamemailbox($mbox, $created, $renamed) ? 'ren' : 'noren';
echo "\n";
echo imap_unsubscribe($mbox, $renamed) ? 'unsub' : 'nounsub';
echo "\n";
echo imap_deletemailbox($mbox, $renamed) ? 'del' : 'nodel';
echo "\n";

// Invalid connection shapes (closed) — Zend TypeError, no crash.
imap_close($mbox);
try {
    imap_list($mbox, $dir.'/', '*');
    echo "noclose\n";
} catch (TypeError $e) {
    echo "closed\n";
}

@unlink($inbox);
@rmdir($dir);
?>
--EXPECT--
11111111
open
create
listed
getmb
sub
lsub
ren
unsub
del
closed
