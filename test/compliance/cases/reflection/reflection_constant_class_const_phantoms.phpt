--TEST--
ReflectionConstant must not advertise ReflectionClassConstant APIs (#28156, php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$c = new ReflectionConstant('PHP_VERSION');
foreach ([
    'getDeclaringClass',
    'getModifiers',
    'getType',
    'hasType',
    'isEnumCase',
    'isFinal',
    'isPrivate',
    'isProtected',
    'isPublic',
    'getDeprecatedMessage',
    'getDeprecatedVersion',
] as $m) {
    echo $m, '=', method_exists($c, $m) ? '1' : '0', "\n";
}

class C28156 { public const X = 1; }
$rcc = new ReflectionClassConstant(C28156::class, 'X');
foreach (['getDeclaringClass', 'getModifiers', 'getType', 'hasType', 'isEnumCase', 'isFinal', 'isPublic'] as $m) {
    echo 'rcc_', $m, '=', method_exists($rcc, $m) ? '1' : '0', "\n";
}
echo 'isDeprecated=', method_exists($c, 'isDeprecated') ? '1' : '0', "\n";
echo 'IS_PUBLIC=', defined('ReflectionConstant::IS_PUBLIC') ? '1' : '0', "\n";
echo 'RCC_IS_PUBLIC=', defined('ReflectionClassConstant::IS_PUBLIC') ? '1' : '0', "\n";
--EXPECT--
getDeclaringClass=0
getModifiers=0
getType=0
hasType=0
isEnumCase=0
isFinal=0
isPrivate=0
isProtected=0
isPublic=0
getDeprecatedMessage=0
getDeprecatedVersion=0
rcc_getDeclaringClass=1
rcc_getModifiers=1
rcc_getType=1
rcc_hasType=1
rcc_isEnumCase=1
rcc_isFinal=1
rcc_isPublic=1
isDeprecated=1
IS_PUBLIC=0
RCC_IS_PUBLIC=1
