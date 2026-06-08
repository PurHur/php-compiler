<?php
// Compile-only (#4600): getrusage() Z_PARAM_LONG numeric-string + array TypeError lowering.
var_dump(getrusage("0") !== false);
try {
    getrusage([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
