<?php
declare(strict_types=1);
/**
 * Maintainer repro for #18026 — nullsafe ?-> short-circuits on non-object bases.
 */
$cases = [
    'int' => (1)?->x ?? 'ns',
    'false' => (false)?->x ?? 'ns',
    'float' => (1.5)?->x ?? 'ns',
    'string' => ('hi')?->x ?? 'ns',
    'array' => ([])?->x ?? 'ns',
    'null' => (null)?->x ?? 'ns',
];
foreach ($cases as $label => $value) {
    echo $label, ':', $value, "\n";
}
