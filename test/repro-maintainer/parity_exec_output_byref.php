<?php
/** Maintainer repro for #11071 — exec() output/result_code by-ref without undefined-variable warnings. */
exec('echo hi', $out, $code);
$ok = is_array($out) && 1 === count($out) && 'hi' === $out[0] && 0 === $code;
echo $ok ? "OK\n" : "FAIL\n";
