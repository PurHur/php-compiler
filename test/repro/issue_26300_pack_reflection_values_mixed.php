<?php
/**
 * #26300 — pack() Reflection: variadic $values must be typed mixed.
 *
 * php-src: ext/standard/basic_functions.stub.php
 *   function pack(string $format, mixed ...$values): string;
 */
$r = new ReflectionFunction('pack');
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : '(none)';
    echo $p->getName(), '=', $type, ' variadic=', $p->isVariadic() ? 'yes' : 'no', "\n";
}
echo bin2hex(pack('C*', 1, 2, 3)), "\n";
