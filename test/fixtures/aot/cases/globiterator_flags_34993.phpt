--TEST--
AOT: GlobIterator getFlags/setFlags excess argc + value (#34993)
--FILE--
<?php
$g = new GlobIterator('/tmp/*');
try {
    $r = $g->getFlags(1);
    echo 'get_excess:', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'get_excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $g->setFlags(0, 1);
    echo "set_excess:ok\n";
} catch (Throwable $e) {
    echo 'set_excess:', get_class($e), ':', $e->getMessage(), "\n";
}
$flags = $g->getFlags();
$g->setFlags(4096);
$after = $g->getFlags();
echo 'ok=', (0 === $flags && 4096 === $after) ? '1' : '0', " flags=$flags after=$after\n";
--EXPECT--
get_excess:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
set_excess:ArgumentCountError:FilesystemIterator::setFlags() expects exactly 1 argument, 2 given
ok=1 flags=0 after=4096
