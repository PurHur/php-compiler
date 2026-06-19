--TEST--
Language: parenthesized (new Class())($args) throws ArgumentCountError on ctor (#10176, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

try {
    (new class {
        public function __construct(public int $x) {}
    })(3);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: Too few arguments to function class@anonymous::__construct(), 0 passed in %s on line %d and exactly 1 expected
