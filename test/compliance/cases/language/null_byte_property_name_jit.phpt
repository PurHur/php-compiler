--TEST--
Language: dynamic property names with leading null byte must throw Error JIT (#5136, zend_object_handlers.c)
--FILE--
<?php
$a = new stdClass();
try {
    $a->{chr(0)} = 1;
    echo "write no catch\n";
} catch (Throwable $e) {
    echo 'write caught ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $x = $a->{chr(0)};
    echo "read no catch\n";
} catch (Throwable $e) {
    echo 'read caught ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $a->{"\0"} = 2;
    echo "literal write no catch\n";
} catch (Throwable $e) {
    echo 'literal write caught ', $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
write caught Error: Cannot access property starting with "\0"
read caught Error: Cannot access property starting with "\0"
literal write caught Error: Cannot access property starting with "\0"
