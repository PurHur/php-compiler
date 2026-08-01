--TEST--
timezone_name_from_abbr Reflection string|false + defaults -1 (#26358, php_date.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('timezone_name_from_abbr');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' def=';
    echo $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-';
    echo "\n";
}
?>
--EXPECT--
ret=string|false
abbr def=-
utcOffset def=-1
isDST def=-1
