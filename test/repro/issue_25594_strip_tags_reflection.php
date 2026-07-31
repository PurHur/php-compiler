<?php
/**
 * #25594 — strip_tags Reflection $allowed_tags is array|string|null.
 * php-src: ext/standard/string.stub.php
 */
$r = new ReflectionFunction('strip_tags');
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), '|', null === $t ? '-' : (string) $t;
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '|def=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '|def=?';
    }
    echo "\n";
}
echo strip_tags('<b>x</b><i>y</i>', ['b']), "\n";
echo strip_tags('<b>x</b><i>y</i>', '<b>'), "\n";
echo strip_tags('<b>x</b><i>y</i>'), "\n";
