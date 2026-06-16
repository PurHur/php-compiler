<?php
declare(strict_types=1);
// Compile-only (#8976): base_convert() must lower enum-case TypeError guards for AOT.
enum Es: string { case A = 'ff'; }
try {
    var_export(base_convert(Es::A, 16, 10));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
