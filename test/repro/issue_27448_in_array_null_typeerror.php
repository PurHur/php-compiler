<?php
// #27448 — AOT in_array($v, null) must TypeError (catchable), not abort exit 134.
try {
    var_export(in_array(1, null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
