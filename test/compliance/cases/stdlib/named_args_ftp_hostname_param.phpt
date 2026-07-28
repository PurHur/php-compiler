--TEST--
ftp hostname Reflection/named args (VM, issue #23644)
--FILE--
<?php
$rf = new ReflectionFunction('ftp_connect');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
try {
    var_export(@ftp_connect(hostname: '127.0.0.1', port: 1, timeout: 1));
    echo PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
hostname
port
timeout
false
