--TEST--
language: ReflectionAttribute/NamedType/ClassConstant/Property excess argc → ArgumentCountError (#30896, php_reflection.c, JIT)
--FILE--
<?php
#[Attribute]
class ReflAttrArgcA { public function __construct(public int $x = 1) {} }
#[ReflAttrArgcA(2)]
class ReflAttrArgcTarget {}
class ReflPropArgcTmp { public int $x = 1; public int $y; }
$a = (new ReflectionClass(ReflAttrArgcTarget::class))->getAttributes()[0];
$t = (new ReflectionFunction('strlen'))->getParameters()[0]->getType();
$u = (new ReflectionFunction(static function (int|string $x) {}))->getParameters()[0]->getType();
$c = new ReflectionClassConstant(DateTime::class, 'ATOM');
$p = new ReflectionProperty(ReflPropArgcTmp::class, 'x');
$o = new ReflPropArgcTmp();
foreach ([
  'attr.getName' => fn() => $a->getName(1),
  'attr.getArguments' => fn() => $a->getArguments(1)[0],
  'attr.newInstance' => fn() => get_class($a->newInstance(1)),
  'named.getName' => fn() => $t->getName(1),
  'named.isBuiltin' => fn() => $t->isBuiltin(1),
  'type.allowsNull' => fn() => $t->allowsNull(1),
  'union.getTypes' => fn() => $u->getTypes(1),
  'cc.getName' => fn() => $c->getName(1),
  'cc.getValue' => fn() => $c->getValue(1),
  'prop.getName' => fn() => $p->getName(1),
  'prop.getValue' => fn() => $p->getValue($o, 1),
  'prop.isInitialized' => fn() => $p->isInitialized($o, 1),
] as $label => $fn) {
  try {
    $fn();
    echo "$label ACCEPTED\n";
  } catch (Throwable $e) {
    echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
  }
}
echo 'ok=', $a->getName(), ',', $t->getName(), ',', $c->getName(), ',', $p->getName(), ',', $p->getValue($o), "\n";
--EXPECT--
attr.getName ArgumentCountError: ReflectionAttribute::getName() expects exactly 0 arguments, 1 given
attr.getArguments ArgumentCountError: ReflectionAttribute::getArguments() expects exactly 0 arguments, 1 given
attr.newInstance ArgumentCountError: ReflectionAttribute::newInstance() expects exactly 0 arguments, 1 given
named.getName ArgumentCountError: ReflectionNamedType::getName() expects exactly 0 arguments, 1 given
named.isBuiltin ArgumentCountError: ReflectionNamedType::isBuiltin() expects exactly 0 arguments, 1 given
type.allowsNull ArgumentCountError: ReflectionType::allowsNull() expects exactly 0 arguments, 1 given
union.getTypes ArgumentCountError: ReflectionUnionType::getTypes() expects exactly 0 arguments, 1 given
cc.getName ArgumentCountError: ReflectionClassConstant::getName() expects exactly 0 arguments, 1 given
cc.getValue ArgumentCountError: ReflectionClassConstant::getValue() expects exactly 0 arguments, 1 given
prop.getName ArgumentCountError: ReflectionProperty::getName() expects exactly 0 arguments, 1 given
prop.getValue ArgumentCountError: ReflectionProperty::getValue() expects at most 1 argument, 2 given
prop.isInitialized ArgumentCountError: ReflectionProperty::isInitialized() expects at most 1 argument, 2 given
ok=ReflAttrArgcA,string,ATOM,x,1
