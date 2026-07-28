<?php
// FAILS ON AOT — #24161. str_starts_with() returns false for inputs that plainly match.
//
// Bounding evidence: str_contains() on an identical shape (same subject, same call-result argument)
// is CORRECT on AOT, so this is not string builtins generally and not the call-result argument —
// it is str_starts_with specifically. The failure mode varies by subject:
//     literal      -> bool(false)                      (wrong answer, the line below)
//     variable     -> munmap_chunk(): invalid pointer  (heap corruption, core dump)
//     call result  -> bool(false)                      (wrong answer, the line below)
// The variable form is deliberately NOT used here so this case fails deterministically with a
// readable diff rather than a core dump. Deterministic: 0/3 runs matched.
$s = '  Hello World  ';
var_dump(str_starts_with('Hello World', 'Hello'));
var_dump(str_starts_with(trim($s), 'Hello'));
var_dump(str_contains(trim($s), 'World'));
