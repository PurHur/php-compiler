<?php
/**
 * Issue #26760 — under PROFILE=8.5, SHUT_* match php-src 8.5 sockets.stub.php.
 */
echo 'SHUT_RD=', (int) (defined('SHUT_RD') && SHUT_RD === 0), "\n";
echo 'SHUT_WR=', (int) (defined('SHUT_WR') && SHUT_WR === 1), "\n";
echo 'SHUT_RDWR=', (int) (defined('SHUT_RDWR') && SHUT_RDWR === 2), "\n";
echo "ok\n";
