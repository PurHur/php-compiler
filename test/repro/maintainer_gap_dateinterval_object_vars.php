<?php
// #22446 — DateInterval get_object_vars / get_mangled_object_vars Zend wire
$i = new DateInterval('P1DT2H');
echo 'gov=', json_encode(get_object_vars($i)), "\n";
echo 'mangled=', json_encode(get_mangled_object_vars($i)), "\n";
$f = DateInterval::createFromDateString('1 day');
echo 'from_gov=', json_encode(get_object_vars($f)), "\n";
echo 'from_mangled=', json_encode(get_mangled_object_vars($f)), "\n";
