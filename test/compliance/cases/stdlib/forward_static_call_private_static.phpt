--TEST--
forward_static_call() — inherited private static must TypeError (#11919)
--FILE--
<?php
class FscPrivateStaticParent {
    private static function secret(): int { return 7; }
}
class FscPrivateStaticChild extends FscPrivateStaticParent {
    public static function probe(): void {
        try {
            forward_static_call([self::class, 'secret']);
            echo "fail\n";
        } catch (TypeError $e) {
            echo str_contains($e->getMessage(), 'cannot access private method') ? "ok\n" : "bad\n";
        }
        try {
            forward_static_call_array([self::class, 'secret'], []);
            echo "fail\n";
        } catch (TypeError $e) {
            echo str_contains($e->getMessage(), 'cannot access private method') ? "ok\n" : "bad\n";
        }
    }
}
FscPrivateStaticChild::probe();
--EXPECT--
ok
ok
