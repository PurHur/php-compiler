--TEST--
stdlib forward_static_call_array() — Class::method string at global scope (#11693)
--FILE--
<?php
class FscGlobalClassMethodProbe {
    public static function ok(): string { return 'ok'; }
}
echo forward_static_call_array('FscGlobalClassMethodProbe::ok', []), "\n";
--EXPECT--
ok
