<?php
declare(strict_types=1);
try {
    var_export(getservbyname(null, 'tcp'));
    echo "\nNO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(getservbyname('http', null));
    echo "\nNO_THROW_protocol\n";
} catch (Throwable $e) {
    echo 'protocol: ', get_class($e), ': ', $e->getMessage(), "\n";
}
