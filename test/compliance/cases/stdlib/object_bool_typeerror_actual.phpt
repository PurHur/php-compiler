--TEST--
object builtins TypeError actual false|true not bool (#30100)
--FILE--
<?php
foreach (['spl_object_hash', 'spl_object_id', 'get_object_vars', 'get_mangled_object_vars'] as $fn) {
    foreach ([false, true] as $v) {
        try {
            $fn($v);
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), "\n";
        }
    }
}
try {
    get_class(false);
} catch (Throwable $e) {
    echo 'get_class:', $e->getMessage(), "\n";
}
?>
--EXPECT--
spl_object_hash:spl_object_hash(): Argument #1 ($object) must be of type object, false given
spl_object_hash:spl_object_hash(): Argument #1 ($object) must be of type object, true given
spl_object_id:spl_object_id(): Argument #1 ($object) must be of type object, false given
spl_object_id:spl_object_id(): Argument #1 ($object) must be of type object, true given
get_object_vars:get_object_vars(): Argument #1 ($object) must be of type object, false given
get_object_vars:get_object_vars(): Argument #1 ($object) must be of type object, true given
get_mangled_object_vars:get_mangled_object_vars(): Argument #1 ($object) must be of type object, false given
get_mangled_object_vars:get_mangled_object_vars(): Argument #1 ($object) must be of type object, true given
get_class:get_class(): Argument #1 ($object) must be of type object, false given
