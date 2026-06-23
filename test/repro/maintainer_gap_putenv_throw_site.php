<?php
declare(strict_types=1);

try {
    putenv('=invalid');
} catch (ValueError $e) {
    echo str_contains($e->getFile(), 'maintainer_gap_putenv_throw_site.php') ? "file_ok\n" : "file_bad\n";
    echo $e->getLine() >= 1 ? "line_ok\n" : "line_bad\n";
}
