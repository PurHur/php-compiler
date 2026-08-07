--TEST--
Language: ReflectionClass::resetAsLazyGhost restores uninitialized lazy state (#6125, #28516)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Svc {
    public string $id = '';
    public function __construct(string $tag = '') {
        $this->id = $tag;
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('init');
});
$ref->markLazyObjectAsInitialized($lazy);
echo 'marked=', $lazy->id, "\n";
$ref->resetAsLazyGhost($lazy, function (Svc $o) {
    $o->__construct('init');
});
echo 'reset_uninit=', $ref->isUninitializedLazyObject($lazy) ? 'yes' : 'no', "\n";
echo 'reinit=', $lazy->id, "\n";
echo 'phantom_resetAsLazyObject=', method_exists($ref, 'resetAsLazyObject') ? '1' : '0', "\n";
--EXPECT--
marked=
reset_uninit=yes
reinit=init
phantom_resetAsLazyObject=0
