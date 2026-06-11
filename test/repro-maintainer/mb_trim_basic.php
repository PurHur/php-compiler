<?php

// U+3000 ideographic space padding
$s = "\u{3000}hello\u{3000}";
echo mb_trim($s), '|', mb_ltrim($s), '|', mb_rtrim($s), "\n";
echo mb_trim($s, " \u{3000}"), "\n";
