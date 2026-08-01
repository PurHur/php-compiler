<?php
// Repro #26466 — mb_convert_encoding Reflection matches php-src mbstring.stub.php
$r = new ReflectionFunction('mb_convert_encoding');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->getType() ? (string) $p->getType() : '<none>', PHP_EOL;
}
echo 'return:', $r->getReturnType() ? (string) $r->getReturnType() : '<none>', PHP_EOL;
