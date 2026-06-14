--TEST--
stdlib array_combine() — object keys must Error under JIT (ext/standard/array.c #4161)
--FILE--
<?php
function run(): void {
    try {
        array_combine([new stdClass()], [1]);
        echo "no error\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
run();
--EXPECT--
Object of class stdClass could not be converted to string
