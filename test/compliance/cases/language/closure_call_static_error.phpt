--TEST--
language: static Closure::call() throws Error (#7144, zend_closures.c php-src-strict)
--FILE--
<?php
$c = function (): int { return 42; };
try {
    Closure::call($c, new stdClass());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class C {
    private function m(): string { return 'ok'; }
}
$cl = Closure::bind(function (): string { return $this->m(); }, new C(), C::class);
echo $cl->call(new C()), "\n";
--EXPECT--
Error: Non-static method Closure::call() cannot be called statically
ok
