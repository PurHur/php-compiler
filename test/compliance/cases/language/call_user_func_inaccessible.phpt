--TEST--
call_user_func* inaccessible private/protected TypeError (#25709)
--FILE--
<?php
class CufInaccA {
    private function priv($x) { echo "priv:$x\n"; }
    protected function prot() { echo "prot\n"; }
    private static function s() { echo "s\n"; }
}
class CufInaccB extends CufInaccA {
    public function go() {
        call_user_func([$this, 'prot']);
    }
}
$a = new CufInaccA();
try {
    call_user_func([$a, 'priv'], 9);
    echo "priv uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    call_user_func_array([$a, 'priv'], [9]);
    echo "priv array uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    call_user_func([$a, 'prot']);
    echo "prot uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    call_user_func(['CufInaccA', 's']);
    echo "static uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
(new CufInaccB())->go();
--EXPECT--
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, cannot access private method CufInaccA::priv()
TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid callback, cannot access private method CufInaccA::priv()
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, cannot access protected method CufInaccA::prot()
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, cannot access private method CufInaccA::s()
prot
