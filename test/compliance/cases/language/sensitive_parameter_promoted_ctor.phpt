--TEST--
Language: #[\SensitiveParameter] on promoted constructor parameter (#20351)
--FILE--
<?php
class A {
    public function __construct(
        #[\SensitiveParameter]
        public string $secret,
    ) {}
}
$a = new A('x');
echo $a->secret, "\n";
--EXPECT--
x
