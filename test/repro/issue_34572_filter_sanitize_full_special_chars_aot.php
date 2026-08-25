<?php
/** AOT: filter_var FILTER_SANITIZE_* const strings must match Zend (no SIGSEGV; #34572). */
echo 'FULL=', filter_var('<b>x</b>', FILTER_SANITIZE_FULL_SPECIAL_CHARS), "\n";
echo 'SPECIAL=', filter_var('<b>x</b>', FILTER_SANITIZE_SPECIAL_CHARS), "\n";
echo 'ENCODED=', filter_var('<b>', FILTER_SANITIZE_ENCODED), "\n";
echo 'EMAIL=', filter_var('a@b.c!', FILTER_SANITIZE_EMAIL), "\n";
echo 'URL=', filter_var('http://a b', FILTER_SANITIZE_URL), "\n";
echo 'INT=', filter_var('12x3', FILTER_SANITIZE_NUMBER_INT), "\n";
echo 'SLASH=', filter_var("a'b", FILTER_SANITIZE_ADD_SLASHES), "\n";
