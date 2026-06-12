<?php

var_dump(function_exists('grapheme_extract'));
if (function_exists('grapheme_extract')) {
    $s = "a\xCC\x81b";
    var_dump(grapheme_extract($s, 1));
}
