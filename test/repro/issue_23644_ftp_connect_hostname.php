<?php
/** Repro for #23644 — ftp_connect Reflection/named args use Zend $hostname. */
$rf = new ReflectionFunction('ftp_connect');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
try {
    var_export(@ftp_connect(hostname: '127.0.0.1', port: 1, timeout: 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
