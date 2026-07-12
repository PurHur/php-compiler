--TEST--
stdlib printf/sprintf/pack/unpack too-few-args throw ArgumentCountError (#17915, ext/standard/formatted_io.c, pack.c)
--FILE--
<?php
try {
    printf();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    sprintf();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    pack();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    unpack('I');
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
printf() expects at least 1 argument, 0 given
sprintf() expects at least 1 argument, 0 given
pack() expects at least 1 argument, 0 given
unpack() expects at least 2 arguments, 1 given
