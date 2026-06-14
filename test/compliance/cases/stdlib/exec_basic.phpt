--TEST--
stdlib exec() / passthru() / system() — basic subprocess (ext/standard/exec.c, #3278)
--FILE--
<?php
echo function_exists('exec') ? 'exec-yes' : 'exec-no', "\n";
echo function_exists('passthru') ? 'passthru-yes' : 'passthru-no', "\n";
echo function_exists('system') ? 'system-yes' : 'system-no', "\n";
echo function_exists('popen') ? 'popen-yes' : 'popen-no', "\n";

$output = [];
$code = -1;
$last = exec('printf "line1\nline2\n"', $output, $code);
echo 'exec-last:', $last, "\n";
echo 'exec-lines:', implode('|', $output), "\n";
echo 'exec-code:', $code, "\n";

$code = -1;
echo 'passthru:';
$ret = passthru('printf hello', $code);
echo $ret === null ? 'null' : 'bad', ':', $code, "\n";

$code = -1;
echo 'system:';
$last = system('printf "a\nb\n"', $code);
echo 'last:', $last, ':', $code, "\n";
--EXPECT--
exec-yes
passthru-yes
system-yes
popen-yes
exec-last:line2
exec-lines:line1|line2
exec-code:0
passthru:hello
null:0
system:a
b
last:b:0
