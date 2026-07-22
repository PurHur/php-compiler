--TEST--
Closure::fromCallable ReflectionFunction::getName() keeps underlying name (#22330, Zend/zend_closures.c)
--FILE--
<?php
function issue_22330_ping(): string
{
    return 'pong';
}

$f = Closure::fromCallable('issue_22330_ping');
$rf = new ReflectionFunction($f);
echo 'fn=', $rf->getName(), ' isClosure=', (int) $rf->isClosure(), ' invoke=', $f(), "\n";

$g = Closure::fromCallable(['DateTime', 'createFromFormat']);
$rg = new ReflectionFunction($g);
echo 'static=', $rg->getName(), ' isClosure=', (int) $rg->isClosure(), "\n";

$h = Closure::fromCallable('DateTime::createFromFormat');
$rh = new ReflectionFunction($h);
echo 'string=', $rh->getName(), "\n";

class C22330
{
    public function m(): int
    {
        return 7;
    }
}
$c = new C22330();
$i = Closure::fromCallable([$c, 'm']);
$ri = new ReflectionFunction($i);
echo 'instance=', $ri->getName(), ' invoke=', $i(), "\n";

$anon = function () {
    return 1;
};
$ra = new ReflectionFunction($anon);
echo 'anon=', $ra->getName(), ' isClosure=', (int) $ra->isClosure(), "\n";

$s = Closure::fromCallable('strlen');
echo 'strlen=', (new ReflectionFunction($s))->getName(), "\n";
?>
--EXPECT--
fn=issue_22330_ping isClosure=1 invoke=pong
static=createFromFormat isClosure=1
string=createFromFormat
instance=m invoke=7
anon={closure} isClosure=1
strlen=strlen
