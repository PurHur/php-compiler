--TEST--
Language: ReflectionClass/Method/ClassConstant getDeprecatedMessage/Version (#6917)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated(message: 'Legacy API', since: '8.4')]
class Legacy {
    public const OLD = 1;

    #[\Deprecated]
    public function bare(): void {}

    #[\Deprecated(message: 'Old entry', since: '8.4')]
    public function run(): void {}
}

$r = new ReflectionClass(Legacy::class);
echo $r->getDeprecatedMessage(), "\n";
echo $r->getDeprecatedVersion(), "\n";

$m = new ReflectionMethod(Legacy::class, 'run');
echo $m->getDeprecatedMessage(), "\n";
echo $m->getDeprecatedVersion(), "\n";

$bare = new ReflectionMethod(Legacy::class, 'bare');
var_export($bare->getDeprecatedMessage());
echo "\n";
var_export($bare->getDeprecatedVersion());
echo "\n";

$c = new ReflectionClassConstant(Legacy::class, 'OLD');
var_export($c->isDeprecated());
echo "\n";
var_export($c->getDeprecatedMessage());
echo "\n";

$control = new ReflectionClass(stdClass::class);
var_export($control->getDeprecatedMessage());
echo "\n";
--EXPECT--
Legacy API
8.4
Old entry
8.4
NULL
NULL
false
NULL
NULL
