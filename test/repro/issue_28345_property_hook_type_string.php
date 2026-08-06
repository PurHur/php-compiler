<?php
// #28345 — PropertyHookType must be string-backed (php-src ext/reflection/php_reflection.stub.php)
$r = new ReflectionEnum(PropertyHookType::class);
if ((string) $r->getBackingType() !== 'string') {
    fwrite(STDERR, 'backing want string got ' . $r->getBackingType() . "\n");
    exit(1);
}
if (PropertyHookType::Get->value !== 'get' || PropertyHookType::Set->value !== 'set') {
    fwrite(STDERR, 'values want get/set got ' . var_export(PropertyHookType::Get->value, true) . '/' . var_export(PropertyHookType::Set->value, true) . "\n");
    exit(1);
}
if (enum_exists('ReflectionPropertyHookType')) {
    fwrite(STDERR, "phantom ReflectionPropertyHookType\n");
    exit(1);
}
echo "ok\n";
