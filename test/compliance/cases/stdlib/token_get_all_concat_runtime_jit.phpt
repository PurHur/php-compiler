--TEST--
stdlib token_get_all() JIT concat runtime source (#3171 self-host pattern)
--FILE--
<?php
$code = 'echo 1;';
$t = token_get_all('<?php '.$code);
echo ($t[1][0] === T_ECHO ? 'ok' : 'fail'), "\n";
?>
--EXPECT--
ok
