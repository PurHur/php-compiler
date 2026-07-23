--TEST--
stdlib json_encode() unit enum — false + NON_BACKED_ENUM on PHP 8.4 profile (#22681, #22688)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum UE { case A; }

$r = json_encode(UE::A);
var_export($r);
echo "\n";
echo json_last_error(), ':', json_last_error_msg(), "\n";

try {
    json_encode(UE::A, JSON_THROW_ON_ERROR);
    echo "no_exception\n";
} catch (JsonException $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo 'BAD_ValueError:', $e->getMessage(), "\n";
}
--EXPECT--
false
11:Non-backed enums have no default serialization
JsonException:Non-backed enums have no default serialization
