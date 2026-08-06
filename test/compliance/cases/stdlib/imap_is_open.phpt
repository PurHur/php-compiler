--TEST--
stdlib imap_is_open after open/close (#27674)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27674)');
}
if (version_compare(\PHPCompiler\CompilerVersion::languageProfileVersion(), '8.3.0', '<')) {
    die('skip imap_is_open needs PROFILE≥8.3 (#27674)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('imap_is_open') ? '1' : '0';
echo "\n";

$rf = new ReflectionFunction('imap_is_open');
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";
echo $rf->getParameters()[0]->hasType() ? (string)$rf->getParameters()[0]->getType() : '-', "\n";

$fixture = __DIR__ . '/test/fixtures/imap/tiny.mbox';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/imap/tiny.mbox';
}
$mbox = imap_open($fixture, '', '');
echo ($mbox instanceof IMAP\Connection && imap_is_open($mbox)) ? "live\n" : "dead\n";
echo imap_is_open(imap: $mbox) ? "named\n" : "named_bad\n";
imap_close($mbox);
echo imap_is_open($mbox) ? "still\n" : "closed\n";
?>
--EXPECT--
1
imap
bool
IMAP\Connection
live
named
closed
