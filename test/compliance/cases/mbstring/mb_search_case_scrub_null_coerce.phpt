--TEST--
mb_strwidth/search/case/scrub null $string soft-coerce without 8.4 profile (#21061)
--FILE--
<?php
echo var_export(mb_strwidth(null), true), "\n";
echo var_export(mb_strstr(null, 'a'), true), "\n";
echo var_export(mb_convert_case(null, MB_CASE_UPPER), true), "\n";
echo var_export(mb_scrub(null), true), "\n";
echo var_export(mb_str_split(null), true), "\n";
?>
--EXPECT--
0
false
''
''
array (
)
