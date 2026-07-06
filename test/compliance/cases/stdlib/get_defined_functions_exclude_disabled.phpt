--TEST--
Stdlib: get_defined_functions() optional exclude_disabled filters deprecated internals (#4942, basic_functions.c)
--FILE--
<?php
$all = get_defined_functions();
$filtered = get_defined_functions(true);
$allInternal = $all['internal'];
$filteredInternal = $filtered['internal'];
echo count($allInternal) >= count($filteredInternal) ? "count-ok\n" : "count-bad\n";
echo in_array('utf8_encode', $allInternal, true) ? "utf8-listed\n" : "utf8-missing\n";
echo in_array('utf8_encode', $filteredInternal, true) ? "utf8-filtered-bad\n" : "utf8-filtered-ok\n";
echo in_array('utf8_decode', $filteredInternal, true) ? "decode-filtered-bad\n" : "decode-filtered-ok\n";
echo in_array('strlen', $filteredInternal, true) ? "strlen-listed\n" : "strlen-missing\n";
--EXPECT--
count-ok
utf8-listed
utf8-filtered-ok
decode-filtered-ok
strlen-listed
