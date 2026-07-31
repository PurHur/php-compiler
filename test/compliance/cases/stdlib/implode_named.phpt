--TEST--
stdlib implode()/join() named separator:/array:; reject glue:/pieces: (#25589, #9985)
--FILE--
<?php
var_export(implode(separator: '-', array: ['a', 'b']));
echo "\n";
var_export(join(separator: '|', array: ['x', 'y']));
echo "\n";
try {
    var_export(implode(glue: ':', pieces: ['1', '2']));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(join(glue: '-', pieces: ['p', 'q']));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(implode('-', ['a', 'b']));
echo "\n";
var_export(implode(['a', 'b']));
echo "\n";
?>
--EXPECT--
'a-b'
'x|y'
Error: Unknown named parameter $glue
Error: Unknown named parameter $glue
'a-b'
'ab'
