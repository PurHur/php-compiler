--TEST--
Stdlib: get_defined_functions() exclude_disabled filters deprecated on forward 8.4 profile (#4942, #16969)
--FILE--
<?php
$all = get_defined_functions();
$filtered = get_defined_functions(exclude_disabled: true);
$allInternal = $all['internal'];
$filteredInternal = $filtered['internal'];
echo count($allInternal) > count($filteredInternal) ? "count-filtered\n" : "count-not-filtered\n";
echo in_array('utf8_encode', $allInternal, true) ? "utf8-listed\n" : "utf8-missing\n";
echo in_array('utf8_encode', $filteredInternal, true) ? "utf8-still-filtered-bad\n" : "utf8-filtered-ok\n";
echo in_array('utf8_decode', $filteredInternal, true) ? "decode-still-filtered-bad\n" : "decode-filtered-ok\n";
echo in_array('strlen', $filteredInternal, true) ? "strlen-listed\n" : "strlen-missing\n";
--EXPECT--
count-filtered
utf8-listed
utf8-filtered-ok
decode-filtered-ok
strlen-listed
