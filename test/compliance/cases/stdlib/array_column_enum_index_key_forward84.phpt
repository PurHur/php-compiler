--TEST--
stdlib array_column() enum index_key — typed TypeError on PROFILE=8.4 (#26380, Zend/zend.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E: string { case A = 'a'; }
$rows = [['e' => E::A, 'n' => 1]];
try {
    array_column($rows, 'n', 'e');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:Cannot access offset of type E on array
