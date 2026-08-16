<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'WARN: ', $m, "\n";

    return true;
});

$cases = [
    '',
    null,
    '(',
];
foreach ($cases as $pattern) {
    echo 'CASE=', var_export($pattern, true), "\n";
    try {
        new RegexIterator(new ArrayIterator(['a']), $pattern);
        echo "constructed\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$r = new ReflectionMethod(RegexIterator::class, '__construct');
echo 'params=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
