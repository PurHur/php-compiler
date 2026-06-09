<?php
#[\Deprecated(message: 'Legacy API', since: '8.4')]
class Legacy {
    public const OLD = 1;

    #[\Deprecated(message: 'Old entry', since: '8.4')]
    public function run(): void {}
}

$r = new ReflectionClass(Legacy::class);
echo $r->getDeprecatedMessage(), "\n";
echo $r->getDeprecatedVersion(), "\n";

$m = new ReflectionMethod(Legacy::class, 'run');
echo $m->getDeprecatedMessage(), "\n";
echo $m->getDeprecatedVersion(), "\n";

$c = new ReflectionClassConstant(Legacy::class, 'OLD');
var_export($c->isDeprecated());
echo "\n";
var_export($c->getDeprecatedMessage());
echo "\n";

$control = new ReflectionClass(stdClass::class);
var_export($control->getDeprecatedMessage());
echo "\n";
