<?php
declare(strict_types=1);

$a = [1, 2, 3, 4];
try {
    $r = array_splice($a, '1', 2);
    echo 'result=' . json_encode($r) . "\n";
} catch (TypeError $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
