<?php
// Compile-only (#7442): str_getcsv() must lower null TypeError guards for AOT.
try {
    str_getcsv(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
