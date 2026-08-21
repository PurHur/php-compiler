<?php
// #33402 — AOT mkdir must compile and create directories (Zend parity).
$base = '/tmp/phpc_mkdir_33402_' . bin2hex(random_bytes(4));
$ok1 = mkdir($base);
$ok2 = mkdir($base . '/a/b', 0777, true);
$ok3 = is_dir($base . '/a/b');
echo ($ok1 && $ok2 && $ok3) ? "ok\n" : "fail\n";
