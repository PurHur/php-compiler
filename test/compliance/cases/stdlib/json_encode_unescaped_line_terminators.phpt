--TEST--
stdlib json_encode() U+2028/U+2029 stay escaped unless JSON_UNESCAPED_LINE_TERMINATORS (issue #33745)
--FILE--
<?php
$ls = "\xE2\x80\xA8";
$ps = "\xE2\x80\xA9";
echo json_encode($ls), "\n";
echo json_encode($ls, JSON_UNESCAPED_UNICODE), "\n";
echo json_encode($ls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS), "\n";
echo json_encode($ps), "\n";
echo json_encode($ps, JSON_UNESCAPED_UNICODE), "\n";
echo json_encode($ps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS), "\n";
echo json_encode($ls.'x'.$ps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS), "\n";
echo JSON_UNESCAPED_LINE_TERMINATORS, "\n";
echo json_encode($ls, 2304), "\n";
?>
--EXPECT--
"\u2028"
"\u2028"
" "
"\u2029"
"\u2029"
" "
" x "
2048
" "
