--TEST--
stdlib extension_loaded('zmq') false without host pecl-zmq (#23964, pecl-networking-zmq)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('zmq'), "\n";
echo 'in_list=', (int) in_array('zmq', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('zmq')), "\n";
echo 'zmq_context=', (int) function_exists('zmq_context'), "\n";
echo 'ZMQ=', (int) class_exists('ZMQ', false), "\n";
echo 'ZMQContext=', (int) class_exists('ZMQContext', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
zmq_context=0
ZMQ=0
ZMQContext=0
