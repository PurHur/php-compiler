<?php
// Compile-only (#12018): fgetcsv() must lower separator ValueError guards for AOT.
$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
try {
    fgetcsv($f, separator: '');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
