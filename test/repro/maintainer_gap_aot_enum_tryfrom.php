<?php
/**
 * Repro for #26855 — AOT BackedEnum::tryFrom + var_export(..., true) must not segfault.
 * Expect: A NULL
 */
enum E: int
{
    case A = 1;
}
echo E::tryFrom(1)->name, ' ', var_export(E::tryFrom(9), true), "\n";
