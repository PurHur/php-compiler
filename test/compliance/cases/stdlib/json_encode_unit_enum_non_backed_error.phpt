--TEST--
stdlib json_encode() unit enum — JSON_ERROR_NON_BACKED_ENUM message (#22681)
--FILE--
<?php
enum UE { case A; }

var_export(json_encode(UE::A));
echo "\n";
echo json_last_error(), ':', json_last_error_msg(), "\n";
echo JSON_ERROR_NON_BACKED_ENUM, "\n";

try {
    json_encode(UE::A, JSON_THROW_ON_ERROR);
    echo "no_exception\n";
} catch (JsonException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
11:Non-backed enums have no default serialization
11
Non-backed enums have no default serialization
