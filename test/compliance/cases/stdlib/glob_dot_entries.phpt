--TEST--
glob() dotfile pattern includes . and .. entries (#17456, ext/standard/dir.c)
--FILE--
<?php
$tmp = sys_get_temp_dir();
$matches = glob($tmp . '/.*');
$dot = $tmp . '/.';
$parent = $tmp . '/..';
$ok = is_array($matches)
    && in_array($dot, $matches, true)
    && in_array($parent, $matches, true);
echo $ok ? "ok\n" : "bad\n";
$star = glob($tmp . '/*');
$hasDot = is_array($star) && (in_array($dot, $star, true) || in_array($parent, $star, true));
echo $hasDot ? "star_has_dot\n" : "star_ok\n";
--EXPECT--
ok
star_ok
