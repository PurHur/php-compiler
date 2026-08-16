<?php
/**
 * #25758 — strchr Reflection: bool $before_needle = false, return string|false
 * php-src: ext/standard/string.stub.php
 */
$r = new ReflectionFunction('strchr');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->getType() ? (string) $p->getType() : 'none',
        ' opt=', $p->isOptional() ? 'Y' : 'N';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$rt = $r->getReturnType();
echo 'ret=', $rt ? (string) $rt : 'none', "\n";
echo 'runtime=', var_export(strchr(haystack: 'abcdef', needle: 'd', before_needle: true), true), "\n";
