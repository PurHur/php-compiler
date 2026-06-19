--TEST--
Language: constructor-promoted property with hooks — set hook invoked during new (#9877, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public int $x {
            get => $this->x;
            set => $this->x = $value;
        }
    ) {}
}
$c = new C(1);
echo $c->x, "\n";
--EXPECT--
1
