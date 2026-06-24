--TEST--
stdlib exec() — output/result_code by-ref without undefined-variable warnings (#11071, ext/standard/exec.c)
--FILE--
<?php
exec('printf "line1\nline2\n"', $output, $code);
echo 'lines:', implode('|', $output), "\n";
echo 'code:', $code, "\n";
--EXPECT--
lines:line1|line2
code:0
