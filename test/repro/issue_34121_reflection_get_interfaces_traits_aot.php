<?php
// Repro #34121 — ReflectionClass::getInterfaces / getTraits thin AOT
interface IAlpha
{
}

interface IBeta
{
}

trait TOne
{
}

class BaseIface implements IAlpha
{
}

class ChildIface extends BaseIface implements IBeta
{
    use TOne;
}

$r = new ReflectionClass(ChildIface::class);
$if = $r->getInterfaces();
ksort($if);
$parts = [];
foreach ($if as $k => $v) {
    $parts[] = $k.':'.$v->getName();
}
echo implode(';', $parts), '|';
$tr = $r->getTraits();
ksort($tr);
$tparts = [];
foreach ($tr as $k => $v) {
    $tparts[] = $k.':'.$v->getName();
}
echo implode(';', $tparts), "\n";
