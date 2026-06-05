<?php
declare(strict_types=1);
// Compile-only (#5875): hexdec() must lower enum-case TypeError guards for AOT.
enum Es: string { case B = 'ff'; }
try {
    var_export(hexdec(Es::B));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
