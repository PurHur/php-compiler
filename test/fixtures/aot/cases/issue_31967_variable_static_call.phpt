--TEST--
AOT: $obj::method() variable class in a static call (#31967)
--FILE--
<?php
class U {
    public static function method() {
        echo 'U';
    }
}
$obj = new U();
$obj::method();
--EXPECT--
U
