--TEST--
AOT: filter_var() FILTER_SANITIZE_* const strings (#34572, ext/filter/sanitizing_filters.c)
--FILE--
<?php
echo 'FULL=', filter_var('<b>x</b>', FILTER_SANITIZE_FULL_SPECIAL_CHARS), "\n";
echo 'SPECIAL=', filter_var('<b>x</b>', FILTER_SANITIZE_SPECIAL_CHARS), "\n";
echo 'ENCODED=', filter_var('<b>', FILTER_SANITIZE_ENCODED), "\n";
echo 'EMAIL=', filter_var('a@b.c!', FILTER_SANITIZE_EMAIL), "\n";
echo 'URL=', filter_var('http://a b', FILTER_SANITIZE_URL), "\n";
echo 'INT=', filter_var('12x3', FILTER_SANITIZE_NUMBER_INT), "\n";
echo 'SLASH=', filter_var("a'b", FILTER_SANITIZE_ADD_SLASHES), "\n";
--EXPECT--
FULL=&lt;b&gt;x&lt;/b&gt;
SPECIAL=&#60;b&#62;x&#60;/b&#62;
ENCODED=%3Cb%3E
EMAIL=a@b.c!
URL=http://ab
INT=123
SLASH=a\'b
--EXPECT_EXIT--
0
