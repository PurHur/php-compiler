--TEST--
Language: #[\SensitiveParameter] on promoted ctor — property Reflection omits it; param keeps it (#26379)
--FILE--
<?php
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
    echo null === $args ? "noargs\n" : get_class($args[0])."\n";
    echo str_contains($e->getTraceAsString(), 'secret') ? "LEAK\n" : "safe\n";
}
$propNames = array_map(
    static fn ($a) => $a->getName(),
    (new ReflectionProperty(C::class, 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $propNames, true) ? "prop_has_sens\n" : "prop_no_sens\n";
$paramNames = array_map(
    static fn ($a) => $a->getName(),
    (new ReflectionParameter([C::class, '__construct'], 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $paramNames, true) ? "param_has_sens\n" : "param_no_sens\n";
--EXPECT--
SensitiveParameterValue
safe
prop_no_sens
param_has_sens
