<?php

var_export(array_fill('0', '2', 'x'));
echo "\n";

try {
    array_fill(0, -1, 1);
    echo "no throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
