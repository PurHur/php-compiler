<?php
var_export(chr(300));
echo "\n";
var_export(chr("65"));
echo "\n";
try {
    chr(1.5);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
