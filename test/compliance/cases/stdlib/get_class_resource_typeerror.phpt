--TEST--
stdlib get_class() on stream resource — TypeError not Resource pseudo-class (#12840)
--FILE--
<?php
$stream = fopen('php://memory', 'r+');
try {
    get_class($stream);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo class_exists('Resource', false) ? 'yes' : 'no', "\n";
echo ($stream instanceof Resource) ? 'yes' : 'no', "\n";
--EXPECT--
get_class(): Argument #1 ($object) must be of type object, resource given
no
no
