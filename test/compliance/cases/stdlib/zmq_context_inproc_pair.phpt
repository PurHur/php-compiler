--TEST--
stdlib zmq_context/socket/bind inproc PAIR smoke (#6443, pecl-networking-zmq)
--ENV--
PHP_COMPILER_ENABLE_ZMQ=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zmq\ZmqExtensionPolicy::advertisesExtension()) {
    die('skip zmq withheld (#23964)');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['zmq_context', 'zmq_socket', 'zmq_bind', 'zmq_poll', 'zmq_msg_read'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
echo 'ZMQ=', class_exists('ZMQ', false) ? '1' : '0', "\n";
echo 'PAIR=', (string) ZMQ::SOCKET_PAIR, "\n";

$ctx = zmq_context();
$a = zmq_socket($ctx, ZMQ::SOCKET_PAIR);
$b = zmq_socket($ctx, ZMQ::SOCKET_PAIR);
zmq_bind($a, 'inproc://phpc-zmq-6443');
zmq_connect($b, 'inproc://phpc-zmq-6443');
zmq_send($a, 'hello');
$msg = zmq_recv($b);
echo 'msg=', $msg, "\n";
$items = [];
$items[] = [$a, ZMQ::POLL_OUT];
$ready = zmq_poll($items, 0);
echo 'poll=', count($ready) >= 1 ? '1' : '0', "\n";
echo "ok\n";
?>
--EXPECT--
zmq_context=1
zmq_socket=1
zmq_bind=1
zmq_poll=1
zmq_msg_read=1
ZMQ=1
PAIR=0
msg=hello
poll=1
ok
