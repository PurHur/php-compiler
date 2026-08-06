<?php
// Repro for #27278 — token_name() on a runtime token id from token_get_all().
// Expected (Zend/VM/JIT/AOT): 5\nT_ECHO
// count() is fine under thin AOT after #26957; foreach length kept as an equivalent guard.
$t = token_get_all('<?php echo 1;');
$n = 0;
foreach ($t as $_) {
    $n++;
}
echo $n, "\n";
echo token_name($t[1][0]), "\n";
