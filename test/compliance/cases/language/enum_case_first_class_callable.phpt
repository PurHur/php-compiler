--TEST--
Language: enum case as first-class callable (E::A)(...) — compile then Error (#6851)
--FILE--
<?php
enum E: int {
    case A = 1;
}
try {
    (E::A)(...);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Error: Object of type E is not callable
