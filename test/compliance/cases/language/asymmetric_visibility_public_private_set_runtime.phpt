--TEST--
Language: public private(set) — compile fatal (#13960, Zend/zend_compile.c)
--FILE--
<?php
class B {
    public private(set) string $label = 'hi';
}
$b = new B();
echo $b->label, "\n";
try {
    $b->label = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT_EXIT--
255
