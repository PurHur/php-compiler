--TEST--
stdlib setcookie() JIT emits Set-Cookie line
--FILE--
<?php
setcookie('theme', 'dark', 0, '/');
echo "done\n";
--EXPECT--
Set-Cookie: theme=dark; path=/
done
