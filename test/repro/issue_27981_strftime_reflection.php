<?php
/**
 * #27981 — strftime/gmstrftime Reflection return string|false + ?int $timestamp = null.
 */
foreach (['strftime', 'gmstrftime'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[1];
    echo "$fn ret=", (string) $r->getReturnType();
    echo ' ts=', (string) $p->getType();
    echo ' default=', var_export($p->getDefaultValue(), true);
    echo PHP_EOL;
}
echo 'strftime=', strftime('%Y', timestamp: 0), PHP_EOL;
