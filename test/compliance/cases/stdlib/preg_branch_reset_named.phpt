--TEST--
Stdlib: preg_match() branch-reset duplicate named groups (?|…) (#14091, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

preg_match('/(?|(?<n>a)|(?<n>b))/', 'b', $m);
echo $m['n'], "\n";
echo $m[0], "\n";
echo $m[1], "\n";
--EXPECT--
b
b
b
