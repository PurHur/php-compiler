<?php
error_reporting(E_ALL);
try {
    $it = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), fn($v) => $v % 2 === 0);
    echo implode(",", iterator_to_array($it, false)) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ": " . $e->getMessage() . "\n";
}
