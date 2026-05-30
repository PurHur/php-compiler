--TEST--
language: function return type string (#55)
--FILE--
<?php
function greet(): string {
    return 'ok';
}
echo greet();
--EXPECT--
ok
