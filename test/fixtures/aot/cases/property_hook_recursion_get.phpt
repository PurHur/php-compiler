--TEST--
AOT: get-hook self-read on backed typed property fatals Zend uninit Error (#21467)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $x {
        get { return $this->x; }
    }
}
$c = new C();
echo "before\n";
echo $c->x, "\n";
echo "after\n";
--EXPECT_EXIT--
255
--EXPECT--
before
