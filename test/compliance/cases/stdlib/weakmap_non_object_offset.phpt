--TEST--
stdlib WeakMap — non-object offset key TypeError (#5433)
--FILE--
<?php
$wm = new WeakMap();
try {
    var_export($wm[1]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export(isset($wm[1]));
} catch (Throwable $e) {
    echo 'isset: ', $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: WeakMap key must be an object
isset: TypeError: WeakMap key must be an object
