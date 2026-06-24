--TEST--
AOT: get_class_methods()/method_exists() on Exception — casing + __wakeup (#11367)
--FILE--
<?php
declare(strict_types=1);

$methods = get_class_methods('Exception');
echo 'getMessage=' . (in_array('getMessage', $methods, true) ? 'yes' : 'no') . "\n";
echo 'getmessage=' . (in_array('getmessage', $methods, true) ? 'yes' : 'no') . "\n";
echo '__wakeup=' . (in_array('__wakeup', $methods, true) ? 'yes' : 'no') . "\n";
echo 'method_exists_wakeup=' . (method_exists('Exception', '__wakeup') ? 'yes' : 'no') . "\n";
--EXPECT--
getMessage=yes
getmessage=no
__wakeup=yes
method_exists_wakeup=yes
