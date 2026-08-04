<?php
foreach (['preg_grep', 'preg_match_all'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
var_export(preg_grep('/a/', ['a' => 'a', 'b' => 'b']));
echo "\n";
echo preg_match_all('/./', 'ab', $m), "\n";
?>
