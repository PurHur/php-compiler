<?php
namespace N;
try {
    var_export(empty(UNDEF_CONST));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
