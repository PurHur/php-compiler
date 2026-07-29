--TEST--
stdlib output_add_rewrite_var() injects hidden form fields (issue #24370, ext/standard/url_scanner_ex.re)
--FILE--
<?php
output_add_rewrite_var('sid', 'abc');
echo '<form action="x.php"><a href="y.php">z</a></form>', "\n";
ob_flush();
output_reset_rewrite_vars();
echo '<form action="x.php"></form>', "\n";
ob_flush();
output_add_rewrite_var('a', '1');
output_add_rewrite_var('b', '2');
ini_set('url_rewriter.tags', 'a=href,form=');
echo '<form></form>', "\n";
echo '<a href="file.php">l</a>', "\n";
--EXPECT--
<form action="x.php"><input type="hidden" name="sid" value="abc" /><a href="y.php">z</a></form>
<form action="x.php"></form>
<form><input type="hidden" name="a" value="1" /><input type="hidden" name="b" value="2" /></form>
<a href="file.php?a=1&b=2">l</a>
