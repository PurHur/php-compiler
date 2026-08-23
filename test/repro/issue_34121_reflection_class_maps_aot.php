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
echo json_encode(array_keys($r->getInterfaces())), "\n";
echo json_encode(array_keys($r->getTraits())), "\n";
echo gettype($r->getInterfaces()), "\n";
echo gettype($r->getTraits()), "\n";

$s = new ReflectionClass(stdClass::class);
echo json_encode(array_keys($s->getInterfaces())), "\n";
echo json_encode(array_keys($s->getTraits())), "\n";
