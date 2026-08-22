<?php
/**
 * #25478 — http_build_query Reflection: object|array $data, ?string $arg_separator.
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
$r = new ReflectionFunction('http_build_query');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->getType() ?: 'none';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=';
        var_export($p->getDefaultValue());
    }
    echo PHP_EOL;
}
