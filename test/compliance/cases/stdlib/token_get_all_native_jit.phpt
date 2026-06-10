--TEST--
stdlib token_get_all() JIT — T_ECHO probe (#3171, #4561)
--FILE--
<?php
$t = token_get_all('<?php echo 1;');
echo ($t[1][0] === T_ECHO ? 'ok' : 'fail'), "\n";
echo token_name(T_ECHO), "\n";
?>
--EXPECT--
ok
T_ECHO
