<?php
// Issue #22528 — ReflectionParameter::$name + print_r/var_dump (php-src-strict)
class T
{
    public function m(int $a): void
    {
    }
}

$rp = new ReflectionParameter([T::class, 'm'], 'a');
echo $rp->getName(), "\n";
var_export($rp->name);
echo "\n";
echo 'pe_name=', property_exists($rp, 'name') ? '1' : '0', "\n";
echo 'pe_paramName=', property_exists($rp, 'paramName') ? '1' : '0', "\n";
echo 'pe_funcName=', property_exists($rp, 'funcName') ? '1' : '0', "\n";
echo 'pe_paramClass=', property_exists($rp, 'paramClass') ? '1' : '0', "\n";
print_r($rp);
var_dump($rp);
