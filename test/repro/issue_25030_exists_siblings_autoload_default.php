<?php
// #25030 — interface_exists/trait_exists/enum_exists Reflection $autoload default true
foreach (['interface_exists', 'trait_exists', 'enum_exists'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $params = $rf->getParameters();
    echo $fn, '_autoload_default=', var_export($params[1]->getDefaultValue(), true), "\n";
}
$rf = new ReflectionFunction('is_subclass_of');
echo 'is_subclass_of_allow_string=', var_export($rf->getParameters()[2]->getDefaultValue(), true), "\n";
