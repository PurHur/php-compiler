--TEST--
AOT: ($g .= 'A') && ($g .= 'B') yields AB not B (#34558, re-#24506)
--FILE--
<?php
$g = '';
($g .= 'A') && ($g .= 'B');
echo "g=$g\n";
--EXPECT--
g=AB
--EXPECT_EXIT--
0
