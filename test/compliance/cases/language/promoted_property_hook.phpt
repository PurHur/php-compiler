--TEST--
Language: promoted constructor parameters with property hooks (#7313, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
            set => $this->name = strtolower($value);
        },
    ) {}
}
$c = new C('AbC');
echo $c->name, "\n";
--EXPECT--
ABC
