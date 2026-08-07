--TEST--
stdlib socket_shutdown + getopt/setopt aliases (#6533, ext/sockets/sockets.c)
--FILE--
<?php
echo 'shutdown=', (int) function_exists('socket_shutdown'), "\n";
echo 'getopt=', (int) function_exists('socket_getopt'), "\n";
echo 'setopt=', (int) function_exists('socket_setopt'), "\n";
// SHUT_* are PHP 8.5+ only (#26760); default reference profile matches Zend 8.2 (undefined).
echo 'SHUT_RD=', (int) defined('SHUT_RD'), "\n";
echo 'SHUT_WR=', (int) defined('SHUT_WR'), "\n";
echo 'SHUT_RDWR=', (int) defined('SHUT_RDWR'), "\n";
echo 'SOL_SOCKET=', (int) (defined('SOL_SOCKET') && SOL_SOCKET === 1), "\n";

$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair fail\n");
    exit(1);
}
echo 'setopt_ok=', (int) socket_setopt($pair[0], SOL_SOCKET, SO_REUSEADDR, 1), "\n";
$v = socket_getopt($pair[0], SOL_SOCKET, SO_REUSEADDR);
echo 'getopt_val=', (int) $v, "\n";
$how = defined('SHUT_RDWR') ? SHUT_RDWR : 2;
echo 'shutdown_ok=', (int) socket_shutdown($pair[0], $how), "\n";

socket_close($pair[0]);
socket_close($pair[1]);
echo "done\n";
--EXPECT--
shutdown=1
getopt=1
setopt=1
SHUT_RD=0
SHUT_WR=0
SHUT_RDWR=0
SOL_SOCKET=1
setopt_ok=1
getopt_val=1
shutdown_ok=1
done
