--TEST--
Language: non-readonly extends readonly inside try — compile fatal, not catchable (#26739)
--FILE--
<?php
readonly class A {
    public function __construct(public int $x) {}
}
try {
    class B extends A {}
    echo "extended\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
echo "after\n";
var_export(class_exists("B"));
echo "\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Non-readonly class B cannot extend readonly class A
