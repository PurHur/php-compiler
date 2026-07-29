--TEST--
stdlib GRAPHEME_EXTR_* defined with loaded ext/intl (#24128, php-src ext/intl/php_intl.c)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('intl') ? "intl_yes\n" : "intl_no\n";
echo (int) defined('GRAPHEME_EXTR_COUNT'), "\n";
echo (int) defined('GRAPHEME_EXTR_MAXBYTES'), "\n";
echo (int) defined('GRAPHEME_EXTR_MAXCHARS'), "\n";
echo GRAPHEME_EXTR_COUNT, "\n";
echo GRAPHEME_EXTR_MAXBYTES, "\n";
echo GRAPHEME_EXTR_MAXCHARS, "\n";
--EXPECT--
intl_yes
1
1
1
0
1
2
