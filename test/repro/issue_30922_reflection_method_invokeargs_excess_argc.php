<?php

declare(strict_types=1);

/**
 * ReflectionMethod::invokeArgs() excess argc → ArgumentCountError (#30922).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionMethod_invokeArgs
 */
class Issue30922C
{
    public function m($a)
    {
        return $a;
    }
}

$rm = new ReflectionMethod(Issue30922C::class, 'm');
$o = new Issue30922C();
foreach ([
    'hi' => fn () => $rm->invokeArgs($o, [1], 'x'),
    'lo' => fn () => $rm->invokeArgs($o),
    'ok' => fn () => $rm->invokeArgs($o, [1]),
] as $label => $fn) {
    try {
        $v = $fn();
        echo "$label ACCEPTED:", var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
