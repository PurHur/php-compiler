--TEST--
stdlib setrawcookie() JIT emits Set-Cookie line (raw value)
--FILE--
<?php
setrawcookie('theme', 'dark', 0, '/');
echo "done\n";
--EXPECT--
Set-Cookie: theme=dark; path=/
done
