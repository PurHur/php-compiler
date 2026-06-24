<?php
declare(strict_types=1);

$o = new stdClass();
try {
    echo strval($o), "\n";
} catch (Error $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
