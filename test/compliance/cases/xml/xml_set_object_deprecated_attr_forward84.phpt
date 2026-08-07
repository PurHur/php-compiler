--TEST--
ext/xml xml_set_object Reflection #[\Deprecated] under PROFILE=8.4 (#28172, xml.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('xml_set_object');
$n = 0;
$since = null;
$message = null;
foreach ($r->getAttributes() as $a) {
    $name = $a->getName();
    if ('Deprecated' === $name || '\\Deprecated' === $name) {
        ++$n;
        $args = $a->getArguments();
        $since = $args['since'] ?? null;
        $message = $args['message'] ?? null;
    }
}
echo 'deprecated_count=', $n, "\n";
echo 'since=', null === $since ? '(none)' : $since, "\n";
echo 'message=', null === $message ? '(none)' : $message, "\n";
echo 'isDeprecated=', $r->isDeprecated() ? '1' : '0', "\n";
?>
--EXPECT--
deprecated_count=1
since=8.4
message=provide a proper method callable to xml_set_*_handler() functions
isDeprecated=1
