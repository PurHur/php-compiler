--TEST--
Language: match bare enum case arm with enum-case scrutinee must Error (#15875, zend_compile.c)
--FILE--
<?php
enum E { case A; case B; }
try {
    echo match (E::A) {
        A => 'bare',
    };
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo match (E::A) {
    E::A => 'qualified',
}, "\n";
--EXPECT--
Error: Undefined constant "A"
qualified
