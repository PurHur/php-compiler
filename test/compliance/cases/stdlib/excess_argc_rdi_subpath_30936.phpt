--TEST--
stdlib: RecursiveDirectoryIterator getSubPath ArgumentCountError (#30936)
--FILE--
<?php
$it = new RecursiveDirectoryIterator(__DIR__);
$it->rewind();
if (!$it->valid()) {
    echo "empty\n";
    exit(0);
}
foreach ([
    'sub' => static fn () => $it->getSubPath(1),
    'subname' => static fn () => $it->getSubPathname(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$okS = $it->getSubPath();
$okN = $it->getSubPathname();
echo 'ok=', (is_string($okS) && is_string($okN)) ? '1' : '0', "\n";
--EXPECT--
sub ArgumentCountError: RecursiveDirectoryIterator::getSubPath() expects exactly 0 arguments, 1 given
subname ArgumentCountError: RecursiveDirectoryIterator::getSubPathname() expects exactly 0 arguments, 1 given
ok=1
