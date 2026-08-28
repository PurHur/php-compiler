<?php

declare(strict_types=1);

/**
 * AOT: ReflectionFunction::isVariadic() for internal variadic builtins (#23593 / #22045).
 * php-src: ext/reflection/php_reflection.c zim_ReflectionFunctionAbstract_isVariadic
 */
foreach (['array_diff', 'array_map', 'strlen'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', ($r->isVariadic() ? '1' : '0'), "\n";
}
