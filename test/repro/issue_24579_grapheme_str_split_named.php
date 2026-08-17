<?php
// Issue #24579 — grapheme_str_split Reflection + Zend named $string/$length
$rf = new ReflectionFunction('grapheme_str_split');
$n = [];
foreach ($rf->getParameters() as $p) {
    $n[] = $p->getName() . ($p->isOptional() ? '?' : '');
}
echo 'arity=', $rf->getNumberOfParameters(), ' [', implode(',', $n), "]\n";
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', "\n";
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo 'p=', $p->getName();
    echo ' type=', $t ? (string) $t : '(none)';
    echo ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
try {
    var_export(grapheme_str_split(string: 'abcdef', length: 2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    grapheme_str_split(str: 'abcdef');
    echo "legacy_str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
var_export(grapheme_str_split('abcdef', 2));
echo "\n";
