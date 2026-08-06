<?php
/**
 * Issue #27820 — IMAP historical aliases.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27820_imap_aliases.php
 */
declare(strict_types=1);

$names = [
    'imap_fetchtext',
    'imap_header',
    'imap_create',
    'imap_rename',
    'imap_listmailbox',
    'imap_listsubscribed',
];
foreach ($names as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$rf = new ReflectionFunction('imap_fetchtext');
echo 'fetchtext_params=';
foreach ($rf->getParameters() as $i => $p) {
    echo ($i ? ',' : ''), $p->getName();
}
echo "\n";
$rf2 = new ReflectionFunction('imap_create');
echo 'create_params=';
foreach ($rf2->getParameters() as $i => $p) {
    echo ($i ? ',' : ''), $p->getName();
}
echo "\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$dir = sys_get_temp_dir().'/phpc_imap_27820_'.getmypid();
@mkdir($dir, 0777, true);
$inbox = $dir.'/INBOX';
copy($fixture, $inbox);

$mbox = imap_open($inbox, '', '');
$body = imap_body($mbox, 1);
$text = imap_fetchtext($mbox, 1);
echo ($body === $text && is_string($text) && $text !== '') ? 'fetchtext_eq' : 'fetchtext_ne', "\n";

$hi = imap_headerinfo($mbox, 1);
$h = imap_header($mbox, 1);
echo (is_object($hi) && is_object($h) && ($hi->subject ?? null) === ($h->subject ?? null)) ? 'header_eq' : 'header_ne', "\n";

$newBox = $dir.'/NEWBOX';
echo imap_create($mbox, $newBox) ? 'created' : 'nocreate', "\n";
echo is_file($newBox) ? 'file' : 'nofile', "\n";
$renamed = $dir.'/RENAMED';
echo imap_rename($mbox, $newBox, $renamed) ? 'renamed' : 'norename', "\n";
echo is_file($renamed) ? 'renfile' : 'norenfile', "\n";

$list = imap_list($mbox, $dir.'/', '*');
$listAlias = imap_listmailbox($mbox, $dir.'/', '*');
$renReal = realpath($renamed);
echo (is_array($list) && is_array($listAlias) && $renReal !== false && in_array($renReal, $listAlias, true)) ? 'list_ok' : 'list_bad', "\n";

imap_subscribe($mbox, $renamed);
$lsub = imap_lsub($mbox, $dir.'/', '*');
$lsubAlias = imap_listsubscribed($mbox, $dir.'/', '*');
echo (is_array($lsub) && is_array($lsubAlias) && $renReal !== false && in_array($renReal, $lsubAlias, true)) ? 'lsub_ok' : 'lsub_bad', "\n";

imap_close($mbox);
@unlink($inbox);
@unlink($renamed);
@rmdir($dir);
