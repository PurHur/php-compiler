<?php
/**
 * #23588 — AOT runtime success path for builtins whose Reflection types this
 * issue fixed. str_ireplace / str_replace / strtr(array) AOT segfaults are
 * pre-existing on master (not introduced by Reflection metadata); probe the
 * native paths that already link: substr_replace, two-string strtr, substr_count.
 */
echo 'substr_replace=', substr_replace('abcdef', 'X', 2, 1), "\n";
echo 'strtr=', strtr('abc', 'a', 'A'), "\n";
echo 'substr_count=', substr_count('abab', 'ab'), "\n";
