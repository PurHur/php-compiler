<?php
// #27631 — AOT array_map(null) must TypeError (catchable), not silent [].
try {
    $r = array_map('strval', null);
    echo 'NO_THROW:'.gettype($r).':'.count($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    $r = array_map('strval', $a);
    echo 'NO_THROW:'.gettype($r).':'.count($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
