<?php
class Svc {
    public string $id = '';
    public function __construct(string $id = '') {
        $this->id = $id;
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('x');
});
$init = $ref->getLazyInitializer($lazy);
echo 'init=', (null === $init ? 'null' : 'callable'), "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo 'after_mark=', $lazy->id, "\n";
$init2 = $ref->getLazyInitializer($lazy);
echo 'init2=', (null === $init2 ? 'null' : 'callable'), "\n";

$plain = new Svc('p');
$ref->resetAsLazyGhost($plain, function (Svc $o) {
    $o->__construct('reset');
});
echo 'reset_before=', $plain->id, "\n";
echo 'reset_after=', $plain->id, "\n";
