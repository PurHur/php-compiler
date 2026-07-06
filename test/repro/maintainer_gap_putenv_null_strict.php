<?php
declare(strict_types=1);

try {
    putenv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
