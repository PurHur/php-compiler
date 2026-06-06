<?php
#[\Deprecated(message: "Legacy API", since: "8.4")]
class Legacy {
    #[\Deprecated]
    public function run(): void {}
}

class Control {}

$r = new ReflectionClass(Legacy::class);
var_export($r->isDeprecated());
echo "\n";
$m = new ReflectionMethod(Legacy::class, 'run');
var_export($m->isDeprecated());
echo "\n";

$rc = new ReflectionClass(Control::class);
var_export($rc->isDeprecated());
echo "\n";

ini_set('error_reporting', '32767');
$inst = new Legacy();
$inst->run();
