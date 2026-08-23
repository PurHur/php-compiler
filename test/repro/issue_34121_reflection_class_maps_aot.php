<?php
// Repro #34121 — ReflectionClass getInterfaces / getTraits thin AOT
interface I34121
{
}
interface J34121 extends I34121
{
}
trait T34121
{
}
class C34121 implements J34121
{
    use T34121;
}

$r = new ReflectionClass(C34121::class);
$ifaces = [];
foreach ($r->getInterfaces() as $name => $rc) {
    $ifaces[] = $name.'='.$rc->getName();
}
sort($ifaces);
echo implode(',', $ifaces), '|';
$traits = [];
foreach ($r->getTraits() as $name => $rc) {
    $traits[] = $name.'='.$rc->getName();
}
sort($traits);
echo implode(',', $traits), '|';

$s = new ReflectionClass(stdClass::class);
echo count($s->getInterfaces()), ',', count($s->getTraits()), "\n";
