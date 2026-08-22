--TEST--
setcookie/setrawcookie Reflection expires_or_options is array|int (#25380)
--FILE--
<?php
foreach (['setcookie', 'setrawcookie'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        if ($p->getName() !== 'expires_or_options') {
            continue;
        }
        echo $fn, '|', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
}
?>
--EXPECT--
setcookie|array|int
setrawcookie|array|int
