<?php

try {
    $result = substr(null, 0);
    echo "BUG: got '" . $result . "' instead of TypeError\n";
} catch (\TypeError $e) {
    echo "OK: " . $e->getMessage() . "\n";
}
