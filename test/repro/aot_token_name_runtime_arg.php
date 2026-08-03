<?php
// Repro for #27278 — token_name() on a runtime token id from token_get_all().
// Expected (Zend/VM/JIT/AOT): 5\nT_ECHO
// Note: AOT count() on arrays currently aborts (cross-cutting HT metadata; see #26957).
// Use foreach length so the same observable 5\nT_ECHO is verified under thin AOT.
$t = token_get_all('<?php echo 1;');
$n = 0;
foreach ($t as $_) {
    $n++;
}
echo $n, "\n";
echo token_name($t[1][0]), "\n";
