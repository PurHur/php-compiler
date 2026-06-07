--TEST--
Language: IS_REPEATABLE attributes + ReflectionAttribute::isRepeated() (#6912)
--FILE--
<?php
#[Attribute(Attribute::IS_REPEATABLE)]
class Rep {
    public function __construct(public int $n = 0) {}
}

#[Rep(0)]
#[Rep(1)]
class Target {}

$attrs = (new ReflectionClass(Target::class))->getAttributes();
echo count($attrs), "\n";
foreach ($attrs as $a) {
    $n = $a->getArguments()[0] ?? '';
    echo $a->isRepeated() ? "rep{$n}" : "norep{$n}", "\n";
}
--EXPECT--
2
rep0
rep1
