<?php
/** Repro for #23656 — ftp_login/get/put Reflection + Zend named args. */
foreach (['ftp_login', 'ftp_get', 'ftp_put'] as $fn) {
    echo $fn, ':';
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}
try {
    ftp_login(ftp: null, username: 'u', password: 'p');
    echo "login ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    ftp_login(stream: null, username: 'u', password: 'p');
    echo "legacy stream accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
