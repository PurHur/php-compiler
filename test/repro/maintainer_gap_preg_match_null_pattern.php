<?php

declare(strict_types=1);

try {
    preg_match(null, 'subject');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
