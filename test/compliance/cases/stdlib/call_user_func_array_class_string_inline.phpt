--TEST--
call_user_func_array() — Class::class.'::method' inline string callable (#11694)
--FILE--
<?php
class CufaInlineClassMethodProbe {
    public static function ok(): string {
        return 'ok';
    }
}
echo call_user_func_array(CufaInlineClassMethodProbe::class.'::ok', []), "\n";
--EXPECT--
ok
