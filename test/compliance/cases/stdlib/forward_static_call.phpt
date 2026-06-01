--TEST--
forward_static_call() — late-static scope dispatch (VM, #3197)
--FILE--
<?php
class FscBase {
    public static function target(): string {
        return 'base';
    }
    public static function forwarder(): string {
        return forward_static_call([self::class, 'target']);
    }
    public static function forwarderString(): string {
        return forward_static_call(self::class . '::target');
    }
}
class FscChild extends FscBase {
    public static function target(): string {
        return 'child';
    }
}
echo FscChild::forwarder(), "\n";
echo FscChild::forwarderString(), "\n";

class FscArray {
    public static function run(array $params): string {
        return forward_static_call_array([self::class, 'target'], $params);
    }
    public static function target(): string {
        return 'array_ok';
    }
}
echo FscArray::run([]), "\n";

try {
    forward_static_call('FscBase::target');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
child
child
array_ok
Error
Cannot call forward_static_call() when no class scope is active
