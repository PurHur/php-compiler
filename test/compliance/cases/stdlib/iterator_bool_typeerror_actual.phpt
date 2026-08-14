--TEST--
iterator_to_array/count/apply TypeError actual false|true not bool (#30117)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['iterator_to_array', 'iterator_count', 'iterator_apply'] as $fn) {
    foreach ([false, true, null] as $v) {
        try {
            if ('iterator_apply' === $fn) {
                $fn($v, static function () {
                    return true;
                });
            } else {
                $fn($v);
            }
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), "\n";
        }
    }
}
?>
--EXPECT--
iterator_to_array:iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, false given
iterator_to_array:iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, true given
iterator_to_array:iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, null given
iterator_count:iterator_count(): Argument #1 ($iterator) must be of type Traversable|array, false given
iterator_count:iterator_count(): Argument #1 ($iterator) must be of type Traversable|array, true given
iterator_count:iterator_count(): Argument #1 ($iterator) must be of type Traversable|array, null given
iterator_apply:iterator_apply(): Argument #1 ($iterator) must be of type Traversable, false given
iterator_apply:iterator_apply(): Argument #1 ($iterator) must be of type Traversable, true given
iterator_apply:iterator_apply(): Argument #1 ($iterator) must be of type Traversable, null given
