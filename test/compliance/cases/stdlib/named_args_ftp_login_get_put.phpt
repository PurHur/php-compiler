--TEST--
ftp_login/get/put Reflection + Zend named args (VM, issue #23656)
--FILE--
<?php
foreach (['ftp_login', 'ftp_get', 'ftp_put'] as $fn) {
    echo $fn, ':';
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo PHP_EOL;
}
try {
    ftp_login(ftp: null, username: 'u', password: 'p');
    echo "login ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    ftp_login(stream: null, username: 'u', password: 'p');
    echo "legacy stream accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
ftp_login: ftp username password
ftp_get: ftp local_filename remote_filename mode offset
ftp_put: ftp remote_filename local_filename mode offset
TypeError:ftp_login(): Argument #1 ($ftp) must be of type FTP\Connection, null given
Unknown named parameter $stream
