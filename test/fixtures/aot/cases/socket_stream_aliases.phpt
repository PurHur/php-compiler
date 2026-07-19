--TEST--
AOT: socket_get_status()/socket_set_blocking()/socket_set_timeout() aliases registered (#20903)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('socket_get_status') ? '1' : '0', "\n";
echo function_exists('socket_set_blocking') ? '1' : '0', "\n";
echo function_exists('socket_set_timeout') ? '1' : '0', "\n";
--EXPECT--
1
1
1
