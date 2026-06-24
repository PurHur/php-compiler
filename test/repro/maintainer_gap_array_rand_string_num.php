<?php
declare(strict_types=1);

try {
    $r = array_rand([1, 2, 3], '2');
    echo 'result=' . json_encode($r) . "\n";
} catch (TypeError $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
