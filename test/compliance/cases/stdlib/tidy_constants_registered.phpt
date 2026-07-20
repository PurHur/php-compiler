--TEST--
TIDY_NODETYPE_* / TIDY_TAG_* constants registered (#21605)
--FILE--
<?php
echo (int) defined('TIDY_NODETYPE_ROOT'), "\n";
echo TIDY_NODETYPE_ROOT, "\n";
echo TIDY_NODETYPE_TEXT, "\n";
echo TIDY_NODETYPE_XMLDECL, "\n";
echo (int) defined('TIDY_TAG_A'), "\n";
echo TIDY_TAG_A, "\n";
echo TIDY_TAG_BODY, "\n";
echo TIDY_TAG_HTML, "\n";
echo TIDY_TAG_VIDEO, "\n";
$n = 0;
foreach (get_defined_constants(false) as $k => $_) {
    if (strncmp($k, 'TIDY_', 5) === 0) {
        ++$n;
    }
}
echo $n >= 160 ? 1 : 0, "\n";
?>
--EXPECT--
1
0
4
13
1
1
16
48
151
1
