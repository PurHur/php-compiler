<?php

declare(strict_types=1);

/**
 * Maintainer repro: preg_match()/preg_match_all() enum subject TypeError (#7153).
 *
 * Compare with Zend: php test/repro/maintainer_preg_match_enum.php
 * VM: php bin/vm.php test/repro/maintainer_preg_match_enum.php
 */

enum Color {
    case Red;
}

try {
    preg_match('/red/', Color::Red, $m);
    echo "preg_match uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    preg_match_all('/red/', Color::Red, $m);
    echo "preg_match_all uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
