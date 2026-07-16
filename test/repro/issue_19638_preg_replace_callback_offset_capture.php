<?php
// preg_replace_callback() PREG_OFFSET_CAPTURE (#19638)
$c = 0;
echo preg_replace_callback(
    '/a/',
    function ($m) {
        return is_array($m[0]) ? $m[0][0].'@'.$m[0][1] : $m[0];
    },
    'xa',
    -1,
    $c,
    PREG_OFFSET_CAPTURE
), "|$c\n";
