--TEST--
AOT: htmlspecialchars() null double_encode coerces to false (#29445)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Closures / typed error handlers are deferred on AOT (#1379); DEP text is covered by VM/JIT.
error_reporting(E_ALL & ~E_DEPRECATED);
echo htmlspecialchars('a', ENT_QUOTES, 'UTF-8', null), "\n";
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', null), "\n";
?>
--EXPECT--
a
&amp;
