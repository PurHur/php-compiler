<?php
declare(strict_types=1);

try {
    $r = array_chunk([1, 2, 3], 1.9);
    echo 'result=' . json_encode($r) . "\n";
} catch (TypeError $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
