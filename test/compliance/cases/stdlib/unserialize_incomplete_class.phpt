--TEST--
stdlib unserialize() — allowed_classes false yields __PHP_Incomplete_Class (#4638, var_unserializer.c)
--FILE--
<?php
class Secret {
    public int $secret = 42;
}
$blob = serialize(new Secret());
$obj = unserialize($blob, ['allowed_classes' => false]);
var_export($obj);
echo "\n";
var_export($obj instanceof __PHP_Incomplete_Class);
echo "\n";
set_error_handler(static function () { return true; });
var_export($obj->secret);
echo "\n";
$other = unserialize($blob, ['allowed_classes' => ['Other']]);
var_export($other instanceof __PHP_Incomplete_Class);
echo "\n";
--EXPECT--
\__PHP_Incomplete_Class::__set_state(array(
  '__PHP_Incomplete_Class_Name' => 'Secret',
  'secret' => 42,
))
true
NULL
true
