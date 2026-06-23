--TEST--
stdlib htmlentities() UTF-8 named entity translation (#10734)
--FILE--
<?php
$str = "über";
echo htmlentities($str, ENT_QUOTES, 'UTF-8'), "\n";
echo htmlspecialchars($str, ENT_QUOTES, 'UTF-8'), "\n";
?>
--EXPECT--
&uuml;ber
über
