--TEST--
ReflectionClass lazy-object APIs phantom on PROFILE=8.2 (#25503, Zend/zend_lazy_objects.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$r = new ReflectionClass(stdClass::class);
foreach ([
    'newLazyGhost',
    'newLazyProxy',
    'isUninitializedLazyObject',
    'resetAsLazyGhost',
    'getLazyInitializer',
    'initializeLazyObject',
    'markLazyObjectAsInitialized',
    'createLazyGhost',
    'createLazyProxy',
] as $method) {
    echo $method, '=', method_exists($r, $method) ? '1' : '0', "\n";
}
try {
    $r->newLazyGhost(static function (stdClass $o): void {});
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'newLazyGhost') ? 'undefined' : $e->getMessage(), "\n";
}
--EXPECT--
newLazyGhost=0
newLazyProxy=0
isUninitializedLazyObject=0
resetAsLazyGhost=0
getLazyInitializer=0
initializeLazyObject=0
markLazyObjectAsInitialized=0
createLazyGhost=0
createLazyProxy=0
call=undefined
