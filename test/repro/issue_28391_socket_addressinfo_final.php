<?php

declare(strict_types=1);

/**
 * Repro #28391 — Socket / AddressInfo must be final
 * (php-src ext/sockets/sockets.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28391_socket_addressinfo_final.php
 */
foreach (['Socket', 'AddressInfo'] as $c) {
    echo $c, ' isFinal=', var_export((new ReflectionClass($c))->isFinal(), true), "\n";
}
eval('class BadSocket extends Socket {}');
echo "EXTENDED_OK\n";
