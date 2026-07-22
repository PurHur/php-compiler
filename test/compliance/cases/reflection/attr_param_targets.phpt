--TEST--
Language: ReflectionParameter::getAttributes() on closure/named/method params (#22028)
--FILE--
<?php
#[Attribute]
class TAttr
{
    public function __construct(public int $n = 0)
    {
    }
}

$f = function (#[TAttr(1)] $x) {
};
$c = (new ReflectionFunction($f))->getParameters()[0]->getAttributes();
echo 'closure=', count($c), ' args=';
var_export($c[0]->getArguments());
echo "\n";

function named_fn(#[TAttr(2)] $x)
{
}
$n = (new ReflectionFunction('named_fn'))->getParameters()[0]->getAttributes();
echo 'named=', count($n), ' args=';
var_export($n[0]->getArguments());
echo "\n";

class Host
{
    public function m(#[TAttr(3)] $x)
    {
    }
}
$m = (new ReflectionMethod(Host::class, 'm'))->getParameters()[0]->getAttributes();
echo 'method=', count($m), ' args=';
var_export($m[0]->getArguments());
echo "\n";
--EXPECT--
closure=1 args=array (
  0 => 1,
)
named=1 args=array (
  0 => 2,
)
method=1 args=array (
  0 => 3,
)
