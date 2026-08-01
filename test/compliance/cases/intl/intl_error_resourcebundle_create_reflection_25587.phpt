--TEST--
intl_error_name / resourcebundle_create Reflection + named args (#25587)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip intl Reflection withheld until extension_loaded(\'intl\') (#25587)';
}
?>
--FILE--
<?php
$ie = new ReflectionFunction('intl_error_name');
$p = $ie->getParameters()[0];
echo 'intl_error_name $', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE';
echo ' ret=', $ie->hasReturnType() ? (string) $ie->getReturnType() : 'NONE', "\n";
echo 'named_errorCode=', intl_error_name(errorCode: 0), "\n";
try {
    intl_error_name(error_code: 0);
    echo "legacy_error_code:ok\n";
} catch (Throwable $e) {
    echo 'legacy_error_code:', get_class($e), "\n";
}

$rb = new ReflectionFunction('resourcebundle_create');
$parts = [];
foreach ($rb->getParameters() as $param) {
    $s = ($param->hasType() ? (string) $param->getType() : 'NONE') . ' $' . $param->getName();
    if ($param->isDefaultValueAvailable()) {
        $s .= '=' . var_export($param->getDefaultValue(), true);
    }
    $parts[] = $s;
}
echo 'resourcebundle_create ', implode(', ', $parts);
echo ' ret=', $rb->hasReturnType() ? (string) $rb->getReturnType() : 'NONE', "\n";
$b = resourcebundle_create(locale: 'en', bundle: null);
echo 'named_bundle:', is_object($b) ? get_class($b) : gettype($b), "\n";
try {
    resourcebundle_create(locale: 'en', bundlename: null);
    echo "legacy_bundlename:ok\n";
} catch (Throwable $e) {
    echo 'legacy_bundlename:', get_class($e), "\n";
}
?>
--EXPECT--
intl_error_name $errorCode:int ret=string
named_errorCode=U_ZERO_ERROR
legacy_error_code:Error
resourcebundle_create ?string $locale, ?string $bundle, bool $fallback=true ret=?ResourceBundle
named_bundle:ResourceBundle
legacy_bundlename:Error
