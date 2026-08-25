--TEST--
AOT: ternary concat-assign keeps CV live (#34561)
--FILE--
<?php
$g = '';
true ? ($g .= 'A') : ($g .= 'X');
true ? ($g .= 'B') : ($g .= 'Y');
echo "g=$g\n";
--EXPECT--
g=AB
