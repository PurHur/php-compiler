--TEST--
ext/xml xml_set_object Reflection has no #[\Deprecated] on reference PROFILE (#28172, xml.stub.php)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
    die('skip reference profile must be <8.4 for empty Deprecated attr');
}
?>
--FILE--
<?php
$r = new ReflectionFunction('xml_set_object');
$n = 0;
foreach ($r->getAttributes() as $a) {
    $name = $a->getName();
    if ('Deprecated' === $name || '\\Deprecated' === $name) {
        ++$n;
    }
}
echo 'deprecated_count=', $n, "\n";
echo 'isDeprecated=', $r->isDeprecated() ? '1' : '0', "\n";
?>
--EXPECT--
deprecated_count=0
isDeprecated=0
