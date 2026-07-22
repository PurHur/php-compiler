--TEST--
Language: #[\Deprecated] — ReflectionMethod::isDeprecated() after ReflectionClass API trim (#6803, #22111)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated(message: "Legacy API", since: "8.4")]
class Legacy {
    #[\Deprecated]
    public function run(): void {}

    public function ok(): void {}
}

$dep = new ReflectionMethod(Legacy::class, 'run');
var_export($dep->isDeprecated());
echo "\n";
$plain = new ReflectionMethod(Legacy::class, 'ok');
var_export($plain->isDeprecated());
echo "\n";
--EXPECT--
true
false
