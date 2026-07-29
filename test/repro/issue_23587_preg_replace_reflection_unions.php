<?php
/**
 * #23587 — preg_replace/preg_filter/preg_replace_callback Reflection unions + count untyped.
 * Soft-null DEP path (no declare(strict_types=1)).
 */
foreach (['preg_replace', 'preg_filter', 'preg_replace_callback'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, PHP_EOL;
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '(none)',
            ' byref=', (int) $p->isPassedByReference(),
            PHP_EOL;
    }
}

set_error_handler(static function (int $n, string $m): bool {
    if ($n === E_DEPRECATED) {
        echo 'DEP: ', $m, PHP_EOL;
    }

    return true;
});
@preg_filter(null, 'a', 'b');
