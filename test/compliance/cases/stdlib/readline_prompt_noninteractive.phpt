--TEST--
stdlib readline() — no prompt echo on non-interactive stdin (#12301, ext/readline/readline.c)
--FILE--
<?php
readline('phpc-prompt> ');
echo "ok\n";
--EXPECT--
ok
