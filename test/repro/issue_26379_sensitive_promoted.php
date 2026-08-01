<?php
// Repro #26379 — #[\SensitiveParameter] on promoted ctor param: property Reflection
// must omit it; Exception::getTrace args wrap in SensitiveParameterValue (zend.exception_ignore_args=0).
ini_set('zend.exception_ignore_args', '0');
class C {
    public function __construct(
        #[\SensitiveParameter]
        public string $password,
    ) {
        throw new Exception('fail');
    }
}
try {
    new C('secret');
} catch (Throwable $e) {
    $args = $e->getTrace()[0]['args'] ?? null;
    echo $args === null ? "noargs\n" : get_class($args[0])."\n";
    echo str_contains($e->getTraceAsString(), 'secret') ? "LEAK\n" : "safe\n";
}
$names = array_map(
    fn ($a) => $a->getName(),
    (new ReflectionProperty(C::class, 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $names, true) ? "prop_has_sens\n" : "prop_no_sens\n";
$pnames = array_map(
    fn ($a) => $a->getName(),
    (new ReflectionParameter([C::class, '__construct'], 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $pnames, true) ? "param_has_sens\n" : "param_no_sens\n";
