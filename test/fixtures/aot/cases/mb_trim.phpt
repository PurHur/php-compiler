--TEST--
AOT mb_trim() / mb_ltrim() / mb_rtrim() — UTF-8 ideographic space (#9208)
--FILE--
<?php
$s = "\u{3000}hello\u{3000}";
echo mb_trim($s), '|', mb_ltrim($s), '|', mb_rtrim($s), "\n";
echo mb_trim($s, " \u{3000}"), "\n";
--EXPECT--
hello|hello　|　hello
hello
