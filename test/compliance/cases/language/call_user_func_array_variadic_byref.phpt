--TEST--
Language: call_user_func_array() variadic by-ref writeback (#18015, Zend/zend_execute.c)
--FILE--
<?php
function bump_first(&...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
}

$x = 1;
call_user_func_array('bump_first', [&$x]);
echo $x, "\n";
--EXPECT--
99
