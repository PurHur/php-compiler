--TEST--
Untyped static property with null default (JIT boxed storage, bootstrap helpers)
--FILE--
<?php
class Cache {
    public static $path = null;
}
Cache::$path = 'ok';
echo Cache::$path, "\n";
--EXPECT--
ok
