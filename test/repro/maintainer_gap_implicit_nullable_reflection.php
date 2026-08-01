<?php
/**
 * #26469 — ReflectionParameter on implicit nullable `string $s = null`
 * must report `?string` / allowsNull like php-src.
 */
function g(string $s = null): ?string
{
    return $s;
}

function h(?string $s = null): ?string
{
    return $s;
}

function i(string $s = 'x'): string
{
    return $s;
}

function sensitive(#[\SensitiveParameter] string $password = null): void
{
}

foreach (['g', 'h', 'i', 'sensitive'] as $fn) {
    $p = (new ReflectionFunction($fn))->getParameters()[0];
    echo $fn, ' type=', (string) $p->getType(), ' allowsNull=', $p->allowsNull() ? '1' : '0';
    $t = $p->getType();
    if ($t instanceof ReflectionNamedType) {
        echo ' namedAllowsNull=', $t->allowsNull() ? '1' : '0', ' name=', $t->getName();
    }
    echo "\n";
}
