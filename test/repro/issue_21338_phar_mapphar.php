<?php
declare(strict_types=1);
/** Issue #21338 repro — Phar::mapPhar / interceptFileFuncs registration. */
foreach (['mapPhar', 'interceptFileFuncs'] as $m) {
    echo $m, '=', method_exists('Phar', $m) ? 'yes' : 'MISSING', "\n";
}
