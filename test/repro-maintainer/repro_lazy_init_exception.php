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
    echo 'caught: ', $e->getMessage(), "\n";
}
$stored = $ref->getLazyInitializationException($lazy);
echo $stored?->getMessage() ?? 'missing', "\n";
