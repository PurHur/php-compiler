<?php
try {
    $x = null;
    $y = $x->prop;
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
