<?php
// Repro #34110 — ReflectionClass getInterfaceNames / getTraitNames thin AOT
interface I34110
{
}
interface J34110 extends I34110
{
}
trait T34110
{
}
class C34110 implements J34110
{
    use T34110;
}

$r = new ReflectionClass(C34110::class);
echo json_encode($r->getInterfaceNames()), "\n";
echo json_encode($r->getTraitNames()), "\n";

$s = new ReflectionClass(stdClass::class);
echo json_encode($s->getInterfaceNames()), "\n";
echo json_encode($s->getTraitNames()), "\n";
