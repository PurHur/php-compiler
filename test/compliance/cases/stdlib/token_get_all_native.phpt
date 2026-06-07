--TEST--
stdlib token_get_all() native lexer — T_ECHO probe (issue #4561)
--FILE--
<?php
$t = token_get_all('<?php echo 1;');
echo ($t[1][0] === T_ECHO ? 'ok' : 'fail'), "\n";
echo token_name(T_ECHO), "\n";
?>
--EXPECT--
ok
T_ECHO
