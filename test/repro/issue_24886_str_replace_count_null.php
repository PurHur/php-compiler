<?php
/**
 * #24886 — str_replace/str_ireplace Reflection $count default null (ext/standard/string.stub.php).
 */
foreach (['str_replace', 'str_ireplace'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[3];
    $t = $p->getType();
    echo $fn, ' ', $p->getName(),
        ' type=', null !== $t ? (string) $t : 'none',
        ' allowsNull=', (int) $p->allowsNull(),
        ' def=';
    var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'n/a');
    echo "\n";
}
$c = -1;
echo 'result=', str_replace('a', 'b', 'aa', $c), ' count=', $c, "\n";
