--TEST--
AOT: function return type string (#55)
--FILE--
<?php
function greet(string $name): string {
    return 'Hello '.$name;
}
echo greet('world');
--EXPECT--
Hello world
