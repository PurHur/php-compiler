<?php

declare(strict_types=1);

/**
 * Maintainer repro (#23784): convert_uuencode/convert_uudecode Reflection names + named args.
 */
foreach (['convert_uuencode', 'convert_uudecode'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' params=', $rf->getNumberOfParameters(), PHP_EOL;
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(), PHP_EOL;
    }
}

try {
    $enc = convert_uuencode(string: 'hi');
    echo convert_uudecode(string: $enc), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
