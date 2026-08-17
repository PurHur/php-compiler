--TEST--
AOT: password_verify named args + password_algos (#28917)
--FILE--
<?php
// password_hash() AOT still segfaults on this host (existing password_hash.phpt).
// Gate named password:/hash: plus password_algos() array — the stub surface that
// can run without bcrypt emit.
echo password_verify(password: 'x', hash: 'y') ? "verify_named=1\n" : "verify_named=0\n";
echo is_array(password_algos()) ? "algos_ok\n" : "algos_bad\n";
?>
--EXPECT--
verify_named=0
algos_ok
