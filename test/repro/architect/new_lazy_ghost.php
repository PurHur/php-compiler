<?php
class C {}
try {
    $rc = new ReflectionClass(C::class);
    $o = $rc->newLazyGhost(function (C $c): void {});
    var_dump($o);
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
