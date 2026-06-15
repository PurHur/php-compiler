--TEST--
Language: unit enum case labels in switch — identity match not backing scalar compare (#8806, zend_enum.c)
--FILE--
<?php
enum Unit { case A; case B; }
function f(Unit $u): string {
    switch ($u) {
        case Unit::A: return 'a';
        case Unit::B: return 'b';
    }
}
echo f(Unit::A), "\n";
echo f(Unit::B), "\n";

enum Status { case Ok; case Err; }
function g(Status $s): string {
    switch ($s) {
        case Status::Ok: return 'ok';
        case Status::Err: return 'err';
    }
}
echo g(Status::Ok), "\n";

// Scalar subject must not match enum case label (#5819).
$matched = false;
switch ('A') {
    case Unit::A:
        $matched = true;
        break;
}
echo $matched ? "false_match\n" : "no_match\n";

// Enum subject must match by identity, not scalar coercion.
switch (Unit::A) {
    case Unit::A:
        echo "identity\n";
        break;
    default:
        echo "identity-no\n";
}
?>
--EXPECT--
a
b
ok
no_match
identity
