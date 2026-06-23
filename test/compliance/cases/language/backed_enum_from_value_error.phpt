--TEST--
Language: BackedEnum::from() invalid int backing value throws ValueError (#9889)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    E::from(99);
    echo 'no throw';
} catch (ValueError $e) {
    echo $e->getMessage();
}
echo "\n";
var_export(E::tryFrom(99) === null);
--EXPECT--
99 is not a valid backing value for enum E
true
