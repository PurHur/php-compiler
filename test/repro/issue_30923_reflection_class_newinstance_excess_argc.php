<?php

declare(strict_types=1);

/**
 * ReflectionClass::newInstanceArgs / newInstanceWithoutConstructor excess argc (#30923).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_newInstanceArgs /
 * zim_ReflectionClass_newInstanceWithoutConstructor
 */
class Issue30923C
{
    public $x;

    public function __construct($a = 0)
    {
        $this->x = $a;
    }
}

$rc = new ReflectionClass(Issue30923C::class);
foreach ([
    'args_hi' => fn () => $rc->newInstanceArgs([1], 'x')->x,
    'niwc_hi' => function () use ($rc) {
        $rc->newInstanceWithoutConstructor(1);

        return 'ok';
    },
    'args_ok' => fn () => $rc->newInstanceArgs([1])->x,
    'args_empty' => fn () => $rc->newInstanceArgs([])->x,
    'args_omit' => fn () => $rc->newInstanceArgs()->x,
    'niwc_ok' => function () use ($rc) {
        $rc->newInstanceWithoutConstructor();

        return 'ok';
    },
] as $label => $fn) {
    try {
        $v = $fn();
        echo "$label ACCEPTED:", var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
