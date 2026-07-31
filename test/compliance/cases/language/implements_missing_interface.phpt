--TEST--
Language: class implements missing interface — Error after autoload (#25624, Zend/zend_execute_API.c)
--FILE--
<?php
$loaded = [];
spl_autoload_register(static function (string $c) use (&$loaded): void {
    $loaded[] = $c;
});
try {
    class C25624 implements NoSuchIface25624 {}
    echo "accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
    echo 'loaded=', json_encode($loaded), "\n";
}
// Same-file forward ref must still work (Compiler hoists interfaces before classes).
class Fwd25624 implements I25624 {}
interface I25624 {}
echo class_exists(Fwd25624::class) ? "fwd-ok\n" : "fwd-fail\n";
echo in_array('I25624', class_implements(Fwd25624::class) ?: [], true) ? "impl-ok\n" : "impl-fail\n";
--EXPECT--
Interface "NoSuchIface25624" not found
loaded=["NoSuchIface25624"]
fwd-ok
impl-ok
