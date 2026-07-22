--TEST--
Stdlib: ReflectionReference::fromArrayElement() / getId() (#22065)
--FILE--
<?php
echo class_exists('ReflectionReference') ? "class_ok\n" : "class_missing\n";
$a = ['x' => 1];
$ref =& $a['x'];
$r = ReflectionReference::fromArrayElement($a, 'x');
echo 'id_len=' . strlen($r->getId()) . "\n";
$r3 = ReflectionReference::fromArrayElement($a, 'x');
echo ($r->getId() === $r3->getId() ? "same_id\n" : "diff_id\n");
$b = ['z' => 1];
$r2 = ReflectionReference::fromArrayElement($b, 'z');
echo 'plain=' . ($r2 === null ? 'null' : 'obj') . "\n";
try {
    ReflectionReference::fromArrayElement($a, 'missing');
    echo "no_exc\n";
} catch (ReflectionException $e) {
    echo 'exc:' . $e->getMessage() . "\n";
}
--EXPECT--
class_ok
id_len=20
same_id
plain=null
exc:Array key not found
