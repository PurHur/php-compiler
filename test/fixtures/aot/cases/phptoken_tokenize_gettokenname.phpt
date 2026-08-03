--TEST--
AOT: PhpToken::tokenize getTokenName matches VM (#27263)
--FILE--
<?php
$t = PhpToken::tokenize('<?php echo 1;');
echo $t[1]->getTokenName(), "\n";
?>
--EXPECT--
T_ECHO
