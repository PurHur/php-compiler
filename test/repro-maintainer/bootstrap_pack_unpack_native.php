<?php
// Issue #4674: VM pack()/unpack() must not delegate to host Zend builtins.
$r = unpack('C3', 'ABC');
var_dump($r);
$r = unpack('Nwidth/Nheight', pack('NN', 640, 480));
var_dump($r['width'], $r['height']);
