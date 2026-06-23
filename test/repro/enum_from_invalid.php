<?php
/**
 * Issue #9889 — BackedEnum::from() invalid backing value must throw ValueError.
 */
enum E: int
{
    case A = 1;
}
try {
    E::from(99);
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
echo "\n";
var_export(E::tryFrom(99) === null);
echo "\n";
