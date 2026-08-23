<?php
// Repro #34125 — ReflectionClass::getStaticPropertyValue thin AOT
class BaseGspv
{
    private static $p = 1;
    protected static $r = 3;
    public static $s = 4;
}

class ChildGspv extends BaseGspv
{
    public static $q = 2;
}

class SimpleGspv
{
    public static $s = 42;
}

$rc = new ReflectionClass('SimpleGspv');
echo $rc->getStaticPropertyValue('s'), "\n";

$child = new ReflectionClass('ChildGspv');
echo $child->getStaticPropertyValue('q'), "\n";
echo $child->getStaticPropertyValue('s'), "\n";
echo $child->getStaticPropertyValue('r'), "\n";
echo $child->getStaticPropertyValue('missing', 'def'), "\n";
