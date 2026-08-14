<?php
$r = new ReflectionClass(stdClass::class);
foreach ([
    'newInstance' => fn () => $r->newInstance(1),
    'newInstanceArgs' => fn () => $r->newInstanceArgs([1]),
    'empty' => fn () => $r->newInstance(),
] as $k => $fn) {
    try {
        $o = $fn();
        echo "$k ok ", get_class($o), "\n";
    } catch (Throwable $e) {
        echo "$k ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
