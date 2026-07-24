--TEST--
MessageFormatter::create invalid pattern → null + U_UNMATCHED_BRACES; construct throws IntlException (#22577)
--SKIPIF--
<?php
if (!class_exists('MessageFormatter', false) || !function_exists('intl_get_error_message')) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);

echo 'idle_msg=', var_export(intl_get_error_message(), true), "\n";
echo 'idle_code=', intl_get_error_code(), "\n";

$bad = MessageFormatter::create('en_US', '{invalid');
echo 'create_type=', get_debug_type($bad), "\n";
echo 'create_msg=', var_export(intl_get_error_message(), true), "\n";
echo 'create_code=', intl_get_error_code(), "\n";

try {
    $x = new MessageFormatter('en_US', '{invalid');
    echo 'construct=', get_debug_type($x), "\n";
} catch (Throwable $e) {
    echo 'construct_err=', get_class($e), ':', $e->getMessage(), "\n";
}

$ok = MessageFormatter::create('en_US', '{0}');
echo 'ok_type=', get_debug_type($ok), "\n";
echo 'ok_msg=', var_export(intl_get_error_message(), true), "\n";

$proc = msgfmt_create('en_US', '{invalid');
echo 'proc_type=', get_debug_type($proc), "\n";
echo 'proc_code=', intl_get_error_code(), "\n";
?>
--EXPECT--
idle_msg='U_ZERO_ERROR'
idle_code=0
create_type=null
create_msg='msgfmt_create: message formatter creation failed: U_UNMATCHED_BRACES'
create_code=65801
construct_err=IntlException:msgfmt_create: message formatter creation failed: U_UNMATCHED_BRACES
ok_type=MessageFormatter
ok_msg='U_ZERO_ERROR'
proc_type=null
proc_code=65801
