--TEST--
language: Enum cases/from/tryFrom excess argc → ArgumentCountError (#30864, zend_enum.c)
--FILE--
<?php
enum EnumArgcE: int { case A = 1; }
try {
    var_export(EnumArgcE::cases(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(EnumArgcE::from(1, 2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(EnumArgcE::tryFrom(99, 2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(EnumArgcE::from());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(EnumArgcE::tryFrom());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
EnumArgcE::cases();
echo 'ok=', EnumArgcE::from(1)->name, ',', EnumArgcE::from(1)->value, ',', EnumArgcE::tryFrom(99) === null ? 'NULL' : 'x', "\n";
--EXPECT--
ArgumentCountError: EnumArgcE::cases() expects exactly 0 arguments, 1 given
ArgumentCountError: EnumArgcE::from() expects exactly 1 argument, 2 given
ArgumentCountError: EnumArgcE::tryFrom() expects exactly 1 argument, 2 given
ArgumentCountError: EnumArgcE::from() expects exactly 1 argument, 0 given
ArgumentCountError: EnumArgcE::tryFrom() expects exactly 1 argument, 0 given
ok=A,1,NULL
