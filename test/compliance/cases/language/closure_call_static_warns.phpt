--TEST--
language: Closure::call() on static closure warns and returns null (#25984, zend_closures.c)
--FILE--
<?php
$arrow = static fn() => 1;
try {
    var_export($arrow->call(new stdClass()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$fn = static function () {
    return 1;
};
try {
    var_export($fn->call(new stdClass()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

class C {
    private function m(): string { return 'ok'; }
}
$c = new C();
$bound = Closure::bind(function (): string { return $this->m(); }, $c, C::class);
echo $bound->call($c), "\n";
--EXPECTF--
PHP Warning:  Cannot bind an instance to a static closure in %s on line %d
PHP Warning:  Cannot bind an instance to a static closure in %s on line %d
NULL
NULL
ok
