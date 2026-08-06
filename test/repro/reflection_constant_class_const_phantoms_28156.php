<?php
// Issue #28156 — ReflectionConstant must not advertise ReflectionClassConstant APIs.
$c = new ReflectionConstant('PHP_VERSION');
$phantoms = [
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
];
foreach ($phantoms as $m) {
    if (method_exists($c, $m)) {
        fwrite(STDERR, "fail: ReflectionConstant still has {$m}\n");
        exit(1);
    }
    echo $m, "=0\n";
}

class C28156r { public const X = 1; }
$rcc = new ReflectionClassConstant(C28156r::class, 'X');
foreach (['getDeclaringClass', 'getModifiers', 'isPublic'] as $m) {
    if (!method_exists($rcc, $m)) {
        fwrite(STDERR, "fail: ReflectionClassConstant missing {$m}\n");
        exit(1);
    }
}
if (!method_exists($c, 'isDeprecated')) {
    fwrite(STDERR, "fail: ReflectionConstant missing isDeprecated\n");
    exit(1);
}
if (defined('ReflectionConstant::IS_PUBLIC')) {
    fwrite(STDERR, "fail: ReflectionConstant::IS_PUBLIC should be absent\n");
    exit(1);
}
echo "ok\n";
