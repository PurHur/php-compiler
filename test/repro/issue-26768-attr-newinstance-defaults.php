<?php
// ReflectionAttribute::newInstance() must omit absent optional ctor args (#26768).
// php-src: ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)

#[Attribute]
class Marker {
    public function __construct(public int $flags = 0) {}
}
#[Marker]
class T {}
$attrs = (new ReflectionClass(T::class))->getAttributes();
try {
    $inst = $attrs[0]->newInstance();
    echo 'ok class=', get_class($inst), ' flags=', $inst->flags, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

#[Attribute]
class Marker2 {
    public function __construct(public string $name, public int $n = 1) {}
}
#[Marker2('x')]
class T2 {}
$attrs2 = (new ReflectionClass(T2::class))->getAttributes();
try {
    $inst2 = $attrs2[0]->newInstance();
    echo $inst2->name, '|', $inst2->n, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

#[Attribute]
class Marker3 {}
#[Marker3]
class T3 {}
$attrs3 = (new ReflectionClass(T3::class))->getAttributes();
try {
    echo get_class($attrs3[0]->newInstance()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

#[Attribute]
class MarkerNamed {
    public function __construct(public string $name = 'def', public int $n = 1) {}
}
#[MarkerNamed(n: 7)]
class TNamed {}
$attrsN = (new ReflectionClass(TNamed::class))->getAttributes();
try {
    $instN = $attrsN[0]->newInstance();
    echo $instN->name, '|', $instN->n, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
