--TEST--
stdlib/spl: new FilesystemIterator(__DIR__, SKIP_DOTS) path + flags (#22062)
--FILE--
<?php
$f = new FilesystemIterator(__DIR__, FilesystemIterator::SKIP_DOTS);
if ($f->getPath() !== __DIR__) {
    echo "assign_bad path=", $f->getPath(), " dir=", __DIR__, "\n";
    exit(1);
}
echo 'assign_ok flags=', $f->getFlags(), "\n";
$paren = new FilesystemIterator((__DIR__), FilesystemIterator::SKIP_DOTS);
echo ($paren->getPath() === __DIR__ ? 'paren_ok' : 'paren_bad'), "\n";
--EXPECT--
assign_ok flags=4096
paren_ok
