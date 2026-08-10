<?php
declare(strict_types=1);
try {
    $r = timezone_identifiers_list(null);
    echo var_export($r, true),"\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage(),"\n";
}
