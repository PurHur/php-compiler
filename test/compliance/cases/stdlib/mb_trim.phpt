--TEST--
stdlib mb_trim() / mb_ltrim() / mb_rtrim() — UTF-8 ideographic space (#5957)
--FILE--
<?php
$s = "\u{3000}hello\u{3000}";
echo mb_trim($s), '|', mb_ltrim($s), '|', mb_rtrim($s), "\n";
echo mb_trim($s, " \u{3000}"), "\n";
echo function_exists('mb_trim') ? 'yes' : 'no', "\n";
echo function_exists('mb_ltrim') ? 'yes' : 'no', "\n";
echo function_exists('mb_rtrim') ? 'yes' : 'no', "\n";
--EXPECT--
hello|hello　|　hello
hello
yes
yes
yes
