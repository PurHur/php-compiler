<?php
declare(strict_types=1);

$cls = 'NotARealClass';
try {
    new $cls();
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
