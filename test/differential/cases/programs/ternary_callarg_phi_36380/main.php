<?php
// Lone ?: as call arg must equal assigned temp (#36380).
// php-src: Zend/zend_compile.c — ternary result feeds SEND_VAL.
$allow = false;
$t = $allow ? 0 : 3;
echo "assigned=$t\n";
echo 'inline=';
echo $allow ? 0 : 3;
echo "\n";
echo 'hs=', htmlspecialchars('a"b', $allow ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8'), "\n";
echo 'max=', max($allow ? 1 : 5, 2), "\n";
