<?php
/**
 * Issue #22028 — parameter attributes on closures, named functions, and methods.
 * Assign getAttributes() results before inspecting (nested Host::class chains as
 * call args are a separate evaluation bug).
 */
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
echo 'closure=', count($c);
if ($c) {
    echo ' args=';
    var_export($c[0]->getArguments());
}
echo "\n";

function named_fn(#[TAttr(2)] $x)
{
}
$n = (new ReflectionFunction('named_fn'))->getParameters()[0]->getAttributes();
echo 'named=', count($n);
if ($n) {
    echo ' args=';
    var_export($n[0]->getArguments());
}
echo "\n";

class Host
{
    public function m(#[TAttr(3)] $x)
    {
    }
}
$m = (new ReflectionMethod(Host::class, 'm'))->getParameters()[0]->getAttributes();
echo 'method=', count($m);
if ($m) {
    echo ' args=';
    var_export($m[0]->getArguments());
}
echo "\n";
