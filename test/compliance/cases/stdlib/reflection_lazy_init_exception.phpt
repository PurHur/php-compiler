--TEST--
Stdlib: ReflectionClass::getLazyInitializationException() stores lazy init failure (#6514)
--FILE--
<?php
class Svc {
    public function __construct(public string $id) {
        if ($id === 'fail') {
            throw new RuntimeException('init boom');
        }
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    new Svc('fail');
});
try {
    $lazy->id;
} catch (Throwable $e) {
    echo 'caught', "\n";
}
$stored = $ref->getLazyInitializationException($lazy);
echo $stored?->getMessage(), "\n";
try {
    $ref->getLazyInitializationException(new Svc('ok'));
} catch (TypeError $e) {
    echo 'te_non_lazy', "\n";
}
--EXPECT--
caught
init boom
te_non_lazy

te_non_lazy
