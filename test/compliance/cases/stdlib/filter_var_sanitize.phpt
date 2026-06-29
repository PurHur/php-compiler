--TEST--
Stdlib: filter_var() FILTER_SANITIZE_NUMBER_INT (#11419, ext/filter/sanitizing_filters.c)
--FILE--
<?php
echo filter_var('123abc', FILTER_SANITIZE_NUMBER_INT), "\n";
echo filter_var('<>&"', FILTER_SANITIZE_FULL_SPECIAL_CHARS), "\n";
echo filter_var('hello world', FILTER_UNSAFE_RAW), "\n";
--EXPECT--
123
&lt;&gt;&amp;&quot;
hello world
