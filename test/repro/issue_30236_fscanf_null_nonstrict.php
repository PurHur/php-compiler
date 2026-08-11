<?php

$f = fopen('php://memory', 'r');
try {
    var_export(fscanf($f, null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($f);
