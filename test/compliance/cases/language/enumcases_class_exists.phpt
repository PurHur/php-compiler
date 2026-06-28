--TEST--
Language: builtin EnumCases attribute class exists and is internal (#13057)
--FILE--
<?php
var_export(class_exists('EnumCases', false));
echo "\n";
var_export((new ReflectionClass('EnumCases'))->isInternal());
echo "\n";
enum Suit {
    #[EnumCases('red')]
    case Hearts;
}
$ref = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
var_export(count($ref->getAttributes(EnumCases::class)));
echo "\n";
var_export($ref->getAttributes(EnumCases::class)[0]->newInstance()->name);
echo "\n";
--EXPECT--
true
true
1
'red'
