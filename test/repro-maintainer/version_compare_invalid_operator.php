<?php

declare(strict_types=1);

try {
    version_compare('1', '2', '??');
    echo "no_ex\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
