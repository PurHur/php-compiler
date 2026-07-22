--TEST--
ReflectionClass::getDefaultProperties() includes untyped props without initializer as null (#22047)
--FILE--
<?php
declare(strict_types=1);

class RcDefaultUninitG {
    public $a = 1;
    public $b;
    public int $c = 2;
    public $d = null;
    public int $e;
    public mixed $m;
}

$d = (new ReflectionClass(RcDefaultUninitG::class))->getDefaultProperties();
ksort($d);
$ok = array_key_exists('a', $d)
    && array_key_exists('b', $d)
    && array_key_exists('c', $d)
    && array_key_exists('d', $d)
    && !array_key_exists('e', $d)
    && !array_key_exists('m', $d)
    && 1 === $d['a']
    && null === $d['b']
    && 2 === $d['c']
    && null === $d['d'];
echo $ok ? "defaults_ok\n" : "defaults_bad\n";

$b = new ReflectionProperty(RcDefaultUninitG::class, 'b');
$e = new ReflectionProperty(RcDefaultUninitG::class, 'e');
$m = new ReflectionProperty(RcDefaultUninitG::class, 'm');
echo $b->hasDefaultValue() && null === $b->getDefaultValue() ? "b_ok\n" : "b_bad\n";
echo !$e->hasDefaultValue() ? "e_ok\n" : "e_bad\n";
echo !$m->hasDefaultValue() ? "m_ok\n" : "m_bad\n";
--EXPECT--
defaults_ok
b_ok
e_ok
m_ok
