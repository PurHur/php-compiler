--TEST--
fclose/fread/get_resource_id TypeError actual false|true not bool (#30118)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fclose', 'fread', 'get_resource_id'] as $fn) {
    foreach ([false, true] as $v) {
        try {
            if ('fread' === $fn) {
                $fn($v, 1);
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
fclose:fclose(): Argument #1 ($stream) must be of type resource, false given
fclose:fclose(): Argument #1 ($stream) must be of type resource, true given
fread:fread(): Argument #1 ($stream) must be of type resource, false given
fread:fread(): Argument #1 ($stream) must be of type resource, true given
get_resource_id:get_resource_id(): Argument #1 ($resource) must be of type resource, false given
get_resource_id:get_resource_id(): Argument #1 ($resource) must be of type resource, true given
