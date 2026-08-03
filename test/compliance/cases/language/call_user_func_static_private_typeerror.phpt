--TEST--
call_user_func* class-string non-static private throws TypeError (#27141 / #27144)
--FILE--
<?php
class CufStaticPrivT {
    private function secret(): int { return 7; }
    public function viaThis() {
        return call_user_func([$this, 'secret']);
    }
}
try {
    call_user_func([CufStaticPrivT::class, 'secret']);
    echo "cuf uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'wrong:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    call_user_func_array([CufStaticPrivT::class, 'secret'], []);
    echo "cufa uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'wrong:', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'viaThis:', (new CufStaticPrivT())->viaThis(), "\n";
var_export(is_callable([CufStaticPrivT::class, 'secret']));
echo "\n";
--EXPECT--
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, non-static method CufStaticPrivT::secret() cannot be called statically
TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid callback, non-static method CufStaticPrivT::secret() cannot be called statically
viaThis:7
false
