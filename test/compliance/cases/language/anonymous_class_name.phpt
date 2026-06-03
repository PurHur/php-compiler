--TEST--
Language: anonymous class get_class() name format (issue #4510, Zend zend_compile.c)
--FILE--
<?php
$o = new class extends stdClass {};
$n = get_class($o);
if (!preg_match('/^stdClass@anonymous\x00.+:2\$0$/', $n)) {
    echo 'name_fail:', $n, "\n";
    exit(1);
}
echo "name_ok\n";
echo get_parent_class($o), "\n";
echo is_a($o, stdClass::class) ? "is_a_ok\n" : "is_a_fail\n";
--EXPECT--
name_ok
stdClass
is_a_ok
