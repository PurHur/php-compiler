--TEST--
json_validate() named json/depth/flags + Reflection (issue #23876, PROFILE=8.4)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$rf = new ReflectionFunction('json_validate');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName() . ($p->isOptional() ? '=' : '');
}
echo 'req=', $rf->getNumberOfRequiredParameters(), ' [', implode(',', $names), "]\n";
$depth = $rf->getParameters()[1];
echo 'depth_default=', $depth->isDefaultValueAvailable() ? var_export($depth->getDefaultValue(), true) : 'n/a', "\n";
$flags = $rf->getParameters()[2];
echo 'flags_default=', $flags->isDefaultValueAvailable() ? var_export($flags->getDefaultValue(), true) : 'n/a', "\n";
var_dump(json_validate(json: '{"a":1}'));
var_dump(json_validate('{"a":1}', depth: 512, flags: 0));
var_dump(json_validate('{"a":1}', flags: 0));
var_dump(json_validate('not json', depth: 512));
--EXPECT--
req=1 [json,depth=,flags=]
depth_default=512
flags_default=0
bool(true)
bool(true)
bool(true)
bool(false)
