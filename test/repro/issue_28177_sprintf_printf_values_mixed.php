<?php
/**
 * #28177 — sprintf/printf/fprintf Reflection: variadic values must be typed mixed.
 *
 * php-src: ext/standard/basic_functions.stub.php
 *   sprintf(string $format, mixed ...$values): string
 *   printf(string $format, mixed ...$values): int
 *   fprintf($stream, string $format, mixed ...$values): int
 */
foreach (['sprintf', 'printf', 'fprintf'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $type = (string) $p->getType();
        echo $fn, ' ', $p->getName(), ' type=', $type ?: '<none>',
            ' variadic=', $p->isVariadic() ? 'yes' : 'no', PHP_EOL;
    }
}

// Functional: sprintf positional args still work
echo sprintf('%s=%d', 'x', 42), "\n";
