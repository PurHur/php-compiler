<?php
declare(strict_types=1);
try {
    var_export(checkdnsrr(null));
    echo "\nNO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(dns_check_record(null));
    echo "\nNO_THROW_alias\n";
} catch (Throwable $e) {
    echo 'alias: ', get_class($e), ': ', $e->getMessage(), "\n";
}
