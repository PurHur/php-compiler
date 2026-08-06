--TEST--
stdlib imap_mail_copy / imap_mail_move local mbox (#27780)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27780)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('imap_mail_copy') ? '1' : '0';
echo function_exists('imap_mail_move') ? '1' : '0';
echo defined('CP_UID') ? '1' : '0';
echo defined('CP_MOVE') ? '1' : '0';
echo "\n";

$rf = new ReflectionFunction('imap_mail_copy');
echo $rf->getParameters()[1]->getName(), ',', $rf->getParameters()[2]->getName(), "\n";
$rf2 = new ReflectionFunction('imap_mail_move');
echo $rf2->getParameters()[1]->getName(), "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27780c_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
$other = $dir.'/OTHER';
copy($fixture, $inbox);
touch($other);

$mbox = imap_open($inbox, '', '');
echo imap_num_msg($mbox), "\n";
echo imap_mail_copy($mbox, '1', $other) ? 'copy' : 'nocopy';
echo "\n";
$o = imap_open($other, '', '');
echo imap_num_msg($o), "\n";
imap_close($o);
echo imap_mail_move($mbox, '2', $other) ? 'move' : 'nomove';
echo "\n";
imap_expunge($mbox);
echo imap_num_msg($mbox), "\n";
imap_close($mbox);

@unlink($inbox);
@unlink($other);
@rmdir($dir);
?>
--EXPECT--
1111
message_nums,mailbox
message_nums
2
copy
1
move
1
