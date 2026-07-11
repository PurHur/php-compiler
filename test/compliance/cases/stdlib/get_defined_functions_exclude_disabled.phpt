--TEST--
Stdlib: get_defined_functions() optional exclude_disabled on reference profile (#4942, #16969, basic_functions.c)
--FILE--
<?php
$all = get_defined_functions();
$filtered = get_defined_functions(true);
$allInternal = $all['internal'];
$filteredInternal = $filtered['internal'];
echo count($allInternal) === count($filteredInternal) ? "count-equal\n" : "count-diff\n";
echo in_array('utf8_encode', $allInternal, true) ? "utf8-listed\n" : "utf8-missing\n";
echo in_array('utf8_encode', $filteredInternal, true) ? "utf8-in-filtered\n" : "utf8-missing-filtered\n";
echo in_array('utf8_decode', $filteredInternal, true) ? "decode-in-filtered\n" : "decode-missing-filtered\n";
echo in_array('strlen', $filteredInternal, true) ? "strlen-listed\n" : "strlen-missing\n";
--EXPECT--
count-equal
utf8-listed
utf8-in-filtered
decode-in-filtered
strlen-listed
