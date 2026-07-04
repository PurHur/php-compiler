<?php

declare(strict_types=1);

/**
 * Issue #10279 — get_included_files() in php -r mode must not list virtual compile units.
 *
 * Verification (Zend + VM must print []):
 *   php -r 'echo json_encode(get_included_files()), PHP_EOL;'
 *   php bin/vm.php -r 'echo json_encode(get_included_files()), PHP_EOL;'
 */

$included = get_included_files();
$required = get_required_files();

foreach (['Command line code', 'Standard input code', '-'] as $phantom) {
    if (in_array($phantom, $included, true)) {
        echo 'fail: phantom included entry ', $phantom, "\n";
        exit(1);
    }
    if (in_array($phantom, $required, true)) {
        echo 'fail: phantom required entry ', $phantom, "\n";
        exit(1);
    }
}

echo "ok\n";
