--TEST--
resourcebundle_get_error_code/message procedural aliases (#20853)
--FILE--
<?php
foreach (['resourcebundle_get_error_code', 'resourcebundle_get_error_message'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$rb = resourcebundle_create('en', null);
echo 'code=', resourcebundle_get_error_code($rb), "\n";
echo 'msg=', resourcebundle_get_error_message($rb), "\n";
echo 'match_code=', (int) ($rb->getErrorCode() === resourcebundle_get_error_code($rb)), "\n";
echo 'match_msg=', (int) ($rb->getErrorMessage() === resourcebundle_get_error_message($rb)), "\n";
?>
--EXPECT--
resourcebundle_get_error_code=1
resourcebundle_get_error_message=1
code=0
msg=U_ZERO_ERROR
match_code=1
match_msg=1
