<?php

declare(strict_types=1);

/**
 * ReflectionEnum::isBacked / getBackingType excess argc (#30929).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionEnum_isBacked /
 * zim_ReflectionEnum_getBackingType
 */
enum Issue30929Status: int
{
    case A = 1;
}

$r = new ReflectionEnum(Issue30929Status::class);
foreach ([
    'isBacked_hi' => fn () => $r->isBacked('x'),
    'getBackingType_hi' => fn () => $r->getBackingType('x'),
    'isBacked_ok' => fn () => $r->isBacked(),
    'getBackingType_ok' => fn () => $r->getBackingType()->getName(),
] as $label => $fn) {
    try {
        $v = $fn();
        echo "$label ACCEPTED:", var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
