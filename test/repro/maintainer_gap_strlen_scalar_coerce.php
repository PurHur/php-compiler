<?php

foreach ([false, true, 0, 1, 1.5, null] as $v) {
    try {
        echo gettype($v), '=', strlen($v), ' ';
    } catch (Throwable $e) {
        echo gettype($v), '=', get_class($e), ' ';
    }
}
echo "\n";
