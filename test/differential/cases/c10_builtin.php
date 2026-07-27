<?php
$r=['a'=>'xy','b'=>'zw'];
echo str_replace($r['a'],$r['b'],'xy!'), "\n";
// echo (not var_dump): thin AOT lacks non-scalar var_dump (#23540); max() string is #23951
echo max($r['a'],$r['b']), "\n";
