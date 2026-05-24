--TEST--
Stdlib: set_error_handler() user callback (VM, #1379)
--FILE--
<?php
function my_handler($errno, $errstr, $errfile, $errline) {
    echo "handled:$errno:$errstr:$errline\n";
    return true;
}
set_error_handler('my_handler');
trigger_error('test notice', 1024);
echo "after\n";
restore_error_handler();
trigger_error('default', 1024);
echo "done\n";
--EXPECT--
handled:1024:test notice:0
after
done
