--TEST--
Stdlib: get_defined_functions() exclude_disabled: named parameter (#4942, basic_functions.c)
--FILE--
<?php
$filtered = get_defined_functions(exclude_disabled: true);
$filteredInternal = $filtered['internal'];
echo in_array('utf8_encode', $filteredInternal, true) ? "utf8-filtered-bad\n" : "utf8-filtered-ok\n";
echo in_array('strlen', $filteredInternal, true) ? "strlen-listed\n" : "strlen-missing\n";
--EXPECT--
utf8-filtered-ok
strlen-listed
