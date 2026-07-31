--TEST--
ResourceBundle::__construct matches create + throws on fallback=false failure (#25056)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$a = ResourceBundle::create('en', 'ICUDATA-region');
$b = new ResourceBundle('en', 'ICUDATA-region');
echo 'create=', $a->count(), ' new=', $b->count(), "\n";
echo 'hasCtor=', method_exists('ResourceBundle', '__construct') ? 'yes' : 'no', "\n";
$rc = new ReflectionClass('ResourceBundle');
$ctor = $rc->getConstructor();
echo 'reflCtor=', $ctor ? $ctor->getName() : 'null', "\n";
if ($ctor) {
    $params = $ctor->getParameters();
    echo 'p0=', $params[0]->getName(), ':', (string) $params[0]->getType(), "\n";
    echo 'p1=', $params[1]->getName(), ':', (string) $params[1]->getType(), "\n";
    echo 'p2=', $params[2]->getName(), ':', (string) $params[2]->getType();
    echo ' def=', var_export($params[2]->getDefaultValue(), true), "\n";
}
try {
    new ResourceBundle('xx_NOPE', 'ICUDATA-region', false);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
create=3 new=3
hasCtor=yes
reflCtor=__construct
p0=locale:?string
p1=bundle:?string
p2=fallback:bool def=true
IntlException:Constructor failed
