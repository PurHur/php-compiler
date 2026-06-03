<?php
try {
    var_export(chr(1.5));
} catch (Throwable $e) {
    echo $e::class, "\n";
}
echo "\n";
try {
    var_export(chr("abc"));
} catch (Throwable $e) {
    echo $e::class, "\n";
}
