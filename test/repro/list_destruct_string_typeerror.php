<?php
try {
    [$a] = 'ab';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
try {
    [$b, $c] = 'xy';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
