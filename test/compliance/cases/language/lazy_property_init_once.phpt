--TEST--
language lazy property with get hook initializes once on first read (#17263, zend_lazy_objects.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
--FILE--
<?php
class LazyC {
    public lazy int $buffer {
        get {
            echo "init\n";
            return 42;
        }
    }
}

$c = new LazyC();
var_dump($c->buffer);
var_dump($c->buffer);
--EXPECT--
init
int(42)
int(42)
