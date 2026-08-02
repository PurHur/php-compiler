<?php
/**
 * Repro for #26855 — AOT BackedEnum::tryFrom must not segfault (re-#24208).
 * Expect: A NULL
 *
 * Note: the GitHub issue used var_export() for the miss case; thin AOT currently
 * segfaults on var_export(null) independently. This guard uses an equivalent
 * null check so the tryFrom crash class stays covered.
 */
enum E: int
{
    case A = 1;
}
$miss = E::tryFrom(9);
echo E::tryFrom(1)->name, ' ', ($miss === null ? 'NULL' : 'bad'), "\n";
