<?php
/**
 * #28558 — touch() Reflection: ?int $mtime = null, ?int $atime = null
 * php-src: ext/standard/file.stub.php
 */
$r = new ReflectionFunction('touch');
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : '-';
    $default = $p->isOptional() ? var_export($p->getDefaultValue(), true) : '-';
    echo $p->getName(), ':', $type, ':default=', $default, "\n";
}
