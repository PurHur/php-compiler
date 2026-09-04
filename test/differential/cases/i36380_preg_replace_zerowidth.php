<?php
// Zero-width preg_replace must keep the bumped subject unit (#36380).
// php-src: ext/pcre/php_pcre.c php_pcre_replace_impl
echo preg_replace('/^[ ]{0,2}/', '', 'the rest of it'), "\n";
echo preg_replace('/a*/', 'X', 'bbb'), "\n";
echo preg_replace('/^/', 'Y', 'ab'), "\n";
echo preg_replace('/(?=b)/', 'X', 'ab'), "\n";
