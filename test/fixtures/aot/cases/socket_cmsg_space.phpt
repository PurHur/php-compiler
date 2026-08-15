--TEST--
socket_cmsg_space thin AOT NestedJIT (#31345)
--FILE--
<?php
echo 'cmsg=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS), "\n";
echo 'cmsg1=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1), "\n";
echo "cmsg_linked_ok\n";
?>
--EXPECT--
cmsg=16
cmsg1=24
cmsg_linked_ok
