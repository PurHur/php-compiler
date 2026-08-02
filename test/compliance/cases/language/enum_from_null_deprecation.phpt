--TEST--
Language: BackedEnum::from()/tryFrom(null) emits Zend E_DEPRECATED then coerce (#26786, zend_enum.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg): bool {
    echo 'DEP:', $msg, "\n";
    return true;
});
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
echo 'tryFrom_str=', var_export(E::tryFrom(null), true), "\n";
echo 'tryFrom_int=', var_export(I::tryFrom(null), true), "\n";
try {
    E::from(null);
} catch (Throwable $e) {
    echo 'from_str=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    I::from(null);
} catch (Throwable $e) {
    echo 'from_int=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
tryFrom_str=DEP:E::tryFrom(): Passing null to parameter #1 ($value) of type string|int is deprecated
NULL
tryFrom_int=DEP:I::tryFrom(): Passing null to parameter #1 ($value) of type string|int is deprecated
NULL
DEP:E::from(): Passing null to parameter #1 ($value) of type string|int is deprecated
from_str=ValueError:"0" is not a valid backing value for enum E
DEP:I::from(): Passing null to parameter #1 ($value) of type string|int is deprecated
from_int=ValueError:0 is not a valid backing value for enum I
