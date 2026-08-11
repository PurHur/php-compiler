<?php
// Repro #28424 — iconv Reflection return string|false (iconv.stub.php); runtime false on fail (#25167)
$r = new ReflectionFunction('iconv');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'ok=', var_export(iconv('UTF-8', 'UTF-8', 'café'), true), "\n";
echo 'fail=', var_export(@iconv('UTF-8', 'ASCII', 'café'), true), "\n";
