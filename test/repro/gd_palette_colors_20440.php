<?php
declare(strict_types=1);

/**
 * Repro for #20440 — palette color query/mutate registration.
 */
foreach (['imagecolorsforindex', 'imagecolorclosest', 'imagecolorset', 'imagecolorallocate'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
