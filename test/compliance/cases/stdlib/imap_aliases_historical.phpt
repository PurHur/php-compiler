--TEST--
stdlib IMAP historical aliases fetchtext/header/create/rename/list* (#27820)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27820)');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['imap_fetchtext','imap_header','imap_create','imap_rename','imap_listmailbox','imap_listsubscribed'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$rf = new ReflectionFunction('imap_fetchtext');
echo $rf->getParameters()[1]->getName(), "\n";
$rf2 = new ReflectionFunction('imap_listmailbox');
echo $rf2->getParameters()[1]->getName(), ',', $rf2->getParameters()[2]->getName(), "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$dir = sys_get_temp_dir().'/phpc_imap_27820c_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
$body = imap_body($mbox, 1);
$text = imap_fetchtext($mbox, 1);
echo ($body === $text) ? 'eq' : 'ne';
echo "\n";
$hi = imap_headerinfo($mbox, 1);
$h = imap_header($mbox, 1);
echo (is_object($hi) && is_object($h)) ? 'hdr' : 'nohdr';
echo "\n";
$nb = $dir.'/NEW';
echo imap_create($mbox, $nb) ? 'c' : 'nc';
echo "\n";
$rb = $dir.'/REN';
echo imap_rename($mbox, $nb, $rb) ? 'r' : 'nr';
echo "\n";
$lm = imap_listmailbox($mbox, $dir.'/', '*');
echo is_array($lm) ? 'lm' : 'nlm';
echo "\n";
imap_subscribe($mbox, $rb);
$ls = imap_listsubscribed($mbox, $dir.'/', '*');
echo is_array($ls) ? 'ls' : 'nls';
echo "\n";
imap_close($mbox);
@unlink($inbox);
@unlink($rb);
@rmdir($dir);
?>
--EXPECT--
111111
message_num
reference,pattern
eq
hdr
c
r
lm
ls
