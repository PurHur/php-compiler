--TEST--
ReflectionParameter optional/null/by-ref introspection — internal strlen + user method (#18073)
--FILE--
<?php
declare(strict_types=1);

$p = (new ReflectionFunction('strlen'))->getParameters()[0];
echo 'strlen_optional=', $p->isOptional() ? '1' : '0', "\n";
echo 'strlen_allowsNull=', $p->allowsNull() ? '1' : '0', "\n";
echo 'strlen_byValue=', $p->canBePassedByValue() ? '1' : '0', "\n";
echo 'strlen_byRef=', $p->isPassedByReference() ? '1' : '0', "\n";
echo 'strlen_named=', $p->isNamed() ? '1' : '0', "\n";

class Demo {
    public function f(?int $opt = 1, string &...$rest) {}
}

$rm = new ReflectionMethod(Demo::class, 'f');
$opt = $rm->getParameters()[0];
$rest = $rm->getParameters()[1];
echo 'opt_optional=', $opt->isOptional() ? '1' : '0', "\n";
echo 'opt_allowsNull=', $opt->allowsNull() ? '1' : '0', "\n";
echo 'opt_byRef=', $opt->isPassedByReference() ? '1' : '0', "\n";
echo 'opt_byValue=', $opt->canBePassedByValue() ? '1' : '0', "\n";
echo 'rest_variadic_optional=', $rest->isOptional() ? '1' : '0', "\n";
echo 'rest_byRef=', $rest->isPassedByReference() ? '1' : '0', "\n";
?>
--EXPECT--
strlen_optional=0
strlen_allowsNull=0
strlen_byValue=1
strlen_byRef=0
strlen_named=1
opt_optional=1
opt_allowsNull=1
opt_byRef=0
opt_byValue=1
rest_variadic_optional=1
rest_byRef=1
