--TEST--
Language: extends missing parent → Error Class "…" not found (zend_execute_API.c, #25627)
--FILE--
<?php
$loaded = [];
spl_autoload_register(static function (string $c) use (&$loaded): void {
    $loaded[] = $c;
});
try {
    class C extends NoSuchParentMaintGap25627 {}
    echo "accepted\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo 'loaded=', json_encode($loaded), "\n";
    echo class_exists('C', false) ? "C_exists\n" : "C_missing\n";
}
echo "after\n";

// Same-file forward parent still works (compiler hoists decls).
class ChildFwd extends ParentFwd {}
class ParentFwd {}
echo "forward_ok\n";
?>
--EXPECT--
Error: Class "NoSuchParentMaintGap25627" not found
loaded=["NoSuchParentMaintGap25627"]
C_missing
after
forward_ok
