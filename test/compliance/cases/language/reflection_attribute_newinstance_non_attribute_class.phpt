--TEST--
Language: ReflectionAttribute::newInstance() Error for non-#[Attribute] class (#24930)
--FILE--
<?php
class NotAttr {
    public function __construct(public int $x = 1) {}
}
#[NotAttr(5)]
class Host {}
try {
    $o = (new ReflectionClass(Host::class))->getAttributes()[0]->newInstance();
    echo 'bare:inst=', $o->x, "\n";
} catch (Throwable $e) {
    echo 'bare:', get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute]
class OkAttr {
    public function __construct(public int $x = 1) {}
}
#[OkAttr(9)]
class OkHost {}
try {
    $o = (new ReflectionClass(OkHost::class))->getAttributes()[0]->newInstance();
    echo 'ok:', get_class($o), ':', $o->x, "\n";
} catch (Throwable $e) {
    echo 'ok:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
bare:Error:Attempting to use non-attribute class "NotAttr" as attribute
ok:OkAttr:9
