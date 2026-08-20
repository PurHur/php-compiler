<?php
/**
 * #32621 follow-up — StrReplaceJitHelper NestedJIT match loop must use findAt+slice
 * (inline $subject[$hi] walk sticky-reads under AOT; c10_builtin zwzw / no-op o→0).
 * php-src: ext/standard/string.c php_str_replace_in_subject
 */
echo str_replace('xy', 'zw', 'xy!'), "\n";
echo str_replace('o', '0', 'hello world'), "\n";
