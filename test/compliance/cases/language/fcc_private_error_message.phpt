--TEST--
language: first-class callable inaccessible method Error message (#25689, zend_object_handlers.c)
--FILE--
<?php
class C {
    private function secret() { return 1; }
    protected function prot() { return 2; }
    private static function sm() { return 3; }
    public function make() { return $this->secret(...); }
}
try {
    $f = (new C())->secret(...);
    echo "private instance uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $f = (new C())->prot(...);
    echo "protected instance uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $f = C::sm(...);
    echo "private static uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
class Other {
    public function tryFcc() {
        try {
            return (new C())->secret(...);
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
            return null;
        }
    }
}
(new Other())->tryFcc();
$ok = (new C())->make();
echo $ok(), "\n";
// Closure::fromCallable keeps TypeError wording (#7416).
try {
    Closure::fromCallable([new C, 'secret']);
    echo "fromCallable uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to private method C::secret() from global scope
Error: Call to protected method C::prot() from global scope
Error: Call to private method C::sm() from global scope
Error: Call to private method C::secret() from scope Other
1
TypeError: Failed to create closure from callable: cannot access private method C::secret()
