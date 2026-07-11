--TEST--
Stdlib: get_defined_functions() exclude_disabled: named parameter (#4942, #16969, basic_functions.c)
--FILE--
<?php
$filtered = get_defined_functions(exclude_disabled: true);
$filteredInternal = $filtered['internal'];
echo in_array('utf8_encode', $filteredInternal, true) ? "utf8-in-filtered\n" : "utf8-missing-filtered\n";
echo in_array('strlen', $filteredInternal, true) ? "strlen-listed\n" : "strlen-missing\n";
--EXPECT--
utf8-in-filtered
strlen-listed
