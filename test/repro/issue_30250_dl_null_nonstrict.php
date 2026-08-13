<?php

error_reporting(E_ALL);
try {
    $r = @dl(null);
    echo 'result=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
