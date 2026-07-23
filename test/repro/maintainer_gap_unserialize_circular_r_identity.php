<?php
/**
 * unserialize() circular array R: must restore zval identity (#22652, var_unserializer.re).
 * Zend: identity_ok; prior VM: identity_fail with inner &int(0).
 */
$a = [];
$a[0] = &$a;
$s = serialize($a);
$u = unserialize($s);
echo $s, "\n";
echo ($u === $u[0]) ? "identity_ok\n" : "identity_fail\n";
$blob = 'a:1:{i:0;a:1:{i:0;R:2;}}';
$u2 = unserialize($blob);
echo ($u2 === $u2[0]) ? "blob_ok\n" : "blob_fail\n";
