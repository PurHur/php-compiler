--TEST--
Language: promoted ctor property hooks + default after block — Zend ParseError JIT (#29242)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(
        public int $x {
            get => $this->x * 2;
            set {
                $this->x = $value + 1;
            }
        } = 1
    ) {}
}
$c = new C();
echo $c->x, "\n";
echo "ACCEPTED\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected token "=", expecting ")" in %s on line %d
