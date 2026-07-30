--TEST--
class_alias Reflection required class/alias; autoload default true (#25388)
--FILE--
<?php
$rf = new ReflectionFunction('class_alias');
foreach ($rf->getParameters() as $p) {
    $default = $p->isDefaultValueAvailable()
        ? var_export($p->getDefaultValue(), true)
        : ($p->isOptional() ? 'OPT' : 'REQ');
    echo $p->getName(), '=', $default, "\n";
}
echo 'required=', $rf->getNumberOfRequiredParameters(), "\n";
class Orig {}
class_alias(class: 'Orig', alias: 'Alias1');
echo class_exists('Alias1') ? 'Y' : 'N', "\n";
?>
--EXPECT--
class=REQ
alias=REQ
autoload=true
required=2
Y
