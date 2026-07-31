--TEST--
call_user_func* inaccessible private with __call dispatches magic (#25710)
--FILE--
<?php
class CufPrivCallA {
    private function p($x = null) { echo "priv\n"; }
    public function __call($n, $a) {
        echo "call:$n:", json_encode(array_values($a)), "\n";
        return 'R';
    }
}
class CufPrivCallB {
    private function p() { echo "priv\n"; }
}
class CufPrivCallC {
    private static function p($x) { echo "priv\n"; }
    public static function __callStatic($n, $a) {
        echo "scall:$n:", json_encode(array_values($a)), "\n";
        return 'S';
    }
}

$a = new CufPrivCallA();
echo 'direct:';
$a->p();
echo 'cuf:';
var_export(call_user_func([$a, 'p'], 1, 2));
echo "\n";
echo 'cufa:';
call_user_func_array([$a, 'p'], []);
echo 'is_callable: ';
var_export(is_callable([$a, 'p']));
echo "\n";

$b = new CufPrivCallB();
try {
    call_user_func([$b, 'p']);
    echo "no_magic uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'static: ';
var_export(call_user_func(['CufPrivCallC', 'p'], 9));
echo "\n";
echo 'static is_callable: ';
var_export(is_callable(['CufPrivCallC', 'p']));
echo "\n";
--EXPECT--
direct:call:p:[]
cuf:call:p:[1,2]
'R'
cufa:call:p:[]
is_callable: true
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, cannot access private method CufPrivCallB::p()
static: scall:p:[9]
'S'
static is_callable: true
