<?php
// #22512 — isAccessible is C-internal; ReflectionFunction has no setAccessible.
class T { function f(): void {} private int $p = 1; }
foreach ([
    [ReflectionMethod::class, 'setAccessible'],
    [ReflectionMethod::class, 'isAccessible'],
    [ReflectionProperty::class, 'setAccessible'],
    [ReflectionProperty::class, 'isAccessible'],
    [ReflectionFunction::class, 'setAccessible'],
    [ReflectionFunction::class, 'isAccessible'],
] as [$c, $m]) {
    echo "$c::$m=", method_exists($c, $m) ? 'yes' : 'no', "\n";
}
