--TEST--
date get_object_vars/get_mangled_object_vars DateInterval Zend wire (#22446, ext/date/php_date.c)
--FILE--
<?php
$i = new DateInterval('P1DT2H');
echo 'gov=', json_encode(get_object_vars($i)), "\n";
echo 'mangled=', json_encode(get_mangled_object_vars($i)), "\n";
$f = DateInterval::createFromDateString('1 day');
echo 'from_gov=', json_encode(get_object_vars($f)), "\n";
echo 'from_mangled=', json_encode(get_mangled_object_vars($f)), "\n";
--EXPECT--
gov={"y":0,"m":0,"d":1,"h":2,"i":0,"s":0,"f":0,"invert":0,"days":false,"from_string":false}
mangled={"y":0,"m":0,"d":1,"h":2,"i":0,"s":0,"f":0,"invert":0,"days":false,"from_string":false}
from_gov={"from_string":true,"date_string":"1 day"}
from_mangled={"from_string":true,"date_string":"1 day"}
