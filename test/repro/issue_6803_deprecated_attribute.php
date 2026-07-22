<?php
#[\Deprecated(message: "Legacy API", since: "8.4")]
class Legacy {
    #[\Deprecated]
    public function run(): void {}
}

class Control {}

$m = new ReflectionMethod(Legacy::class, 'run');
var_export($m->isDeprecated());
echo "\n";

ini_set('error_reporting', '32767');
$inst = new Legacy();
$inst->run();
