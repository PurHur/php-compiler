<?php
/**
 * #25258 — header() Reflection $replace default must be true (php-src head.stub.php).
 */
$r = new ReflectionFunction('header');
foreach ($r->getParameters() as $p) {
    if ('replace' === $p->getName()) {
        echo 'replace_default=';
        var_export($p->getDefaultValue());
        echo PHP_EOL;
    }
    if ('response_code' === $p->getName()) {
        echo 'response_code_default=';
        var_export($p->getDefaultValue());
        echo PHP_EOL;
    }
}
