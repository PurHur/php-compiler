--TEST--
Language: #[\Deprecated] — ReflectionClass/Method::isDeprecated() + class instantiation notice (#6803)
--FILE--
<?php
ini_set('error_reporting', '32767');
set_error_handler(function (): bool {
    return true;
});

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

new Legacy();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "class\n" : "no\n";

(new Legacy())->run();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
true
true
false
Class Legacy is deprecated since 8.4, Legacy API
class
Method Legacy::run() is deprecated
