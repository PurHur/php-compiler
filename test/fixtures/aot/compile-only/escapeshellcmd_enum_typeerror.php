<?php
declare(strict_types=1);
// Compile-only (#5876): escapeshellcmd() must lower enum-case TypeError guards for AOT.
enum Es: string { case A = 'x'; }
try {
    var_export(escapeshellcmd(Es::A));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
