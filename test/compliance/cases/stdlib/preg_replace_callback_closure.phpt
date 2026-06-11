--TEST--
stdlib preg_replace_callback() closure callback (issue #4442)
--FILE--
<?php
$out = preg_replace_callback('/./', fn($m) => $m[0].$m[0], 'a');
var_dump($out);
?>
--EXPECT--
string(2) "aa"
