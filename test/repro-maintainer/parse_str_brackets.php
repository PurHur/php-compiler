<?php
$out = [];
parse_str('a=1&b[c]=2&b[d][]=3&b[d][]=4', $out);
var_export($out);
echo "\n";
