--TEST--
stdlib token_get_all() JIT runtime source — T_ECHO probe (#3171)
--FILE--
<?php
$src = '<?php echo 1;';
$t = token_get_all($src);
echo ($t[1][0] === T_ECHO ? 'ok' : 'fail'), "\n";
?>
--EXPECT--
ok
