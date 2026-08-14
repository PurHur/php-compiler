--TEST--
stdlib: SplFileInfo path accessors ArgumentCountError (#30935)
--FILE--
<?php
$f = new SplFileInfo('/etc/hosts');
foreach ([
    'basename' => static fn () => $f->getBasename('.php', 'extra'),
    'path' => static fn () => $f->getPath(1),
    'real' => static fn () => $f->getRealPath(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', is_string($r) ? $r : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$okB = $f->getBasename();
$okP = $f->getPath();
$okR = $f->getRealPath();
echo 'ok=', (
    is_string($okB) && '' !== $okB
    && is_string($okP)
    && (is_string($okR) || false === $okR)
) ? '1' : '0', "\n";
--EXPECT--
basename ArgumentCountError: SplFileInfo::getBasename() expects at most 1 argument, 2 given
path ArgumentCountError: SplFileInfo::getPath() expects exactly 0 arguments, 1 given
real ArgumentCountError: SplFileInfo::getRealPath() expects exactly 0 arguments, 1 given
ok=1
