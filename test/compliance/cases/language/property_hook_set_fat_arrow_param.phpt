--TEST--
Property hook set($param) => fat-arrow shorthand compiles and invokes set hook (#17329, Zend/zend_compile.c)
--FILE--
<?php
class Box {
    private string $stored = '';
    public string $x {
        get => $this->stored;
        set($v) => $this->stored = $v;
    }
}
$box = new Box();
$box->x = 'hi';
echo $box->x, "\n";
--EXPECT--
hi
