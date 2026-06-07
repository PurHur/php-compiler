--TEST--
stdlib — VmReflection::stringArg routes enum operands to catchable TypeError (#7163)
--FILE--
<?php
enum E: string { case A = 'x'; }
$e = E::A;
try {
    new DateTime($e);
} catch (TypeError $t) {
    echo "datetime TypeError\n";
} catch (LogicException $t) {
    echo "datetime LogicException\n";
}
try {
    (new ReflectionClass('stdClass'))->getAttributes($e);
} catch (TypeError $t) {
    echo "reflection TypeError\n";
} catch (LogicException $t) {
    echo "reflection LogicException\n";
}
--EXPECT--
datetime TypeError
reflection TypeError
