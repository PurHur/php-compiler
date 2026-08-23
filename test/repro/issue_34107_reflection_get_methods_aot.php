<?php
// Repro #34107 — ReflectionClass::getMethods thin AOT
class BaseGm
{
    private function p() {}
    protected function r() {}
    public function s() {}
}

class ChildGm extends BaseGm
{
    public function q() {}
    private function t() {}
}

class SimpleGm
{
    public function m() {}
}

$a = (new ReflectionClass(ChildGm::class))->getMethods();
$b = (new ReflectionClass(ChildGm::class))->getMethods(null);
$c = (new ReflectionClass(SimpleGm::class))->getMethods();

$names = array_map(static function ($m) {
    return $m->getName().'@'.$m->class;
}, $a);
sort($names);
echo implode(',', $names), "\n";

$namesNull = array_map(static function ($m) {
    return $m->getName();
}, $b);
sort($namesNull);
echo implode(',', $namesNull), "\n";

$simple = array_map(static function ($m) {
    return $m->getName();
}, $c);
echo implode(',', $simple), "\n";
