--TEST--
Language: dead new AbstractClass must not parseAndCompile-fatal (#25787 / re-#3385)
--FILE--
<?php
abstract class A {}
echo "alive\n";
if (false) {
    new A();
}
echo "done\n";

try {
    new A();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

$c = 'A';
try {
    new $c();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
alive
done
Cannot instantiate abstract class A
Cannot instantiate abstract class A
