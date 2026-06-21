--TEST--
Language: __debugInfo(): ?array null return — var_dump empty object (#9667, Zend/zend.c)
--FILE--
<?php
class C {
    public function __debugInfo(): ?array {
        return null;
    }
}
ob_start();
var_dump(new C());
$output = ob_get_clean();
echo preg_match('/object\\(C\\)#\\d+ \\(0\\)/', $output) ? 'ok' : 'fail';
echo "\n";
?>
--EXPECT--
ok
