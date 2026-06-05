<?php
class C {}
try {
    $o = ReflectionClass::newLazyGhost(C::class, function (C $c): void {});
    var_dump($o);
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
