--TEST--
array pointer builtins TypeError actual false|true not bool (#30114)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['reset', 'next', 'prev', 'current', 'key', 'end'] as $fn) {
    foreach ([false, true, null] as $v) {
        $a = $v;
        try {
            $fn($a);
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), "\n";
        }
    }
}
?>
--EXPECT--
reset:reset(): Argument #1 ($array) must be of type array, false given
reset:reset(): Argument #1 ($array) must be of type array, true given
reset:reset(): Argument #1 ($array) must be of type array, null given
next:next(): Argument #1 ($array) must be of type array, false given
next:next(): Argument #1 ($array) must be of type array, true given
next:next(): Argument #1 ($array) must be of type array, null given
prev:prev(): Argument #1 ($array) must be of type array, false given
prev:prev(): Argument #1 ($array) must be of type array, true given
prev:prev(): Argument #1 ($array) must be of type array, null given
current:current(): Argument #1 ($array) must be of type array, false given
current:current(): Argument #1 ($array) must be of type array, true given
current:current(): Argument #1 ($array) must be of type array, null given
key:key(): Argument #1 ($array) must be of type array, false given
key:key(): Argument #1 ($array) must be of type array, true given
key:key(): Argument #1 ($array) must be of type array, null given
end:end(): Argument #1 ($array) must be of type array, false given
end:end(): Argument #1 ($array) must be of type array, true given
end:end(): Argument #1 ($array) must be of type array, null given
