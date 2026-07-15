--TEST--
stdlib WeakMap — null offset key TypeError (#19198, #5433)
--FILE--
<?php
$wm = new WeakMap();
try {
    $wm[null] = 1;
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export($wm[null]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export(isset($wm[null]));
} catch (Throwable $e) {
    echo 'isset: ', $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: WeakMap key must be an object
TypeError: WeakMap key must be an object
isset: TypeError: WeakMap key must be an object
