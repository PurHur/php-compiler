<?php
declare(strict_types=1);
try {
    $r = DateTimeZone::listIdentifiers(null);
    echo 'oop:'.var_export($r, true),"\n";
} catch (Throwable $e) {
    echo 'oop:'.get_class($e).':'.$e->getMessage(),"\n";
}
try {
    $r = timezone_identifiers_list(null);
    echo 'proc:'.var_export($r, true),"\n";
} catch (Throwable $e) {
    echo 'proc:'.get_class($e).':'.$e->getMessage(),"\n";
}
