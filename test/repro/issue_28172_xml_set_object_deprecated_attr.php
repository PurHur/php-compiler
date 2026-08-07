<?php
/**
 * #28172 — xml_set_object Reflection #[\Deprecated] under PROFILE≥8.4 (ext/xml/xml.stub.php).
 */
$r = new ReflectionFunction('xml_set_object');
$n = 0;
$since = null;
$message = null;
foreach ($r->getAttributes() as $a) {
    $name = $a->getName();
    echo 'attr=', $name, PHP_EOL;
    if ('Deprecated' === $name || '\\Deprecated' === $name) {
        ++$n;
        $args = $a->getArguments();
        $since = $args['since'] ?? null;
        $message = $args['message'] ?? null;
    }
}
echo 'deprecated_count=', $n, PHP_EOL;
echo 'since=', null === $since ? '(none)' : $since, PHP_EOL;
echo 'message=', null === $message ? '(none)' : $message, PHP_EOL;
echo 'isDeprecated=', $r->isDeprecated() ? '1' : '0', PHP_EOL;
