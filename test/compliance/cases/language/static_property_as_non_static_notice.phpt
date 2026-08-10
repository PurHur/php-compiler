--TEST--
Language: accessing static property via -> emits Notice then undefined/dynamic (#30017)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');

$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});

class A {
    public static $x = 1;
}
$a = new A;
$v = $a->x;
echo 'read=', var_export($v, true), "\n";
$a->x = 2;
echo 'static=', var_export(A::$x, true), ' dyn=', var_export($a->x, true), "\n";

class P {
    public static $pub = 1;
    protected static $prot = 2;
    private static $priv = 3;
}
class C extends P {
    public function readProt() {
        return $this->prot;
    }
}
$c = new C;
$v = $c->pub;
echo 'inherit=', var_export($v, true), "\n";
try {
    $c->prot;
    echo "prot-outside=ok\n";
} catch (Error $e) {
    echo 'prot-outside=', $e->getMessage(), "\n";
}
$v = $c->priv;
echo 'priv-parent=', var_export($v, true), "\n";
$v = $c->readProt();
echo 'prot-method=', var_export($v, true), "\n";
echo 'isset=', var_export(isset($c->pub), true), "\n";
echo 'coal=', var_export($c->pub ?? 'd', true), "\n";

restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
--EXPECT--
read=NULL
static=1 dyn=2
inherit=NULL
prot-outside=Cannot access protected property C::$prot
priv-parent=NULL
prot-method=NULL
isset=false
coal='d'
err[8] Accessing static property A::$x as non static
err[2] Undefined property: A::$x
err[8] Accessing static property A::$x as non static
err[8192] Creation of dynamic property A::$x is deprecated
err[8] Accessing static property A::$x as non static
err[8] Accessing static property C::$pub as non static
err[2] Undefined property: C::$pub
err[2] Undefined property: C::$priv
err[8] Accessing static property C::$prot as non static
err[2] Undefined property: C::$prot
