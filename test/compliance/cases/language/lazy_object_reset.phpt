--TEST--
Language: ReflectionClass::resetAsLazyObject restores uninitialized lazy state (#6125)
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
$ref->resetAsLazyObject($lazy);
echo 'reset_uninit=', $ref->isUninitializedLazyObject($lazy) ? 'yes' : 'no', "\n";
echo 'reinit=', $lazy->id, "\n";
try {
    $ref->resetAsLazyObject(new Svc('plain'));
    echo "plain_ok\n";
} catch (TypeError $e) {
    echo "plain_type_error\n";
}
--EXPECT--
marked=
reset_uninit=yes
reinit=init
plain_type_error
