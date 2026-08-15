<?php

declare(strict_types=1);

/**
 * Repro #31345 — thin AOT must lower socket_cmsg_space().
 * php-src: ext/sockets/sendrecvmsg.c PHP_FUNCTION(socket_cmsg_space)
 */
echo 'cmsg=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS), "\n";
echo 'cmsg1=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1), "\n";
echo "cmsg_aot_ok\n";
