<?php

declare(strict_types=1);

try {
    htmlspecialchars_decode(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
