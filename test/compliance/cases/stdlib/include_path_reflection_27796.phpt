--TEST--
get_include_path/set_include_path Reflection return string|false (VM, issue #27796)
--FILE--
<?php
foreach (['get_include_path', 'set_include_path'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
}
$r = new ReflectionFunction('set_include_path');
foreach ($r->getParameters() as $p) {
    echo 'param=$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', "\n";
}
?>
--EXPECT--
get_include_path return=string|false
set_include_path return=string|false
param=$include_path:string
