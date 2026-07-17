--TEST--
forward_static_call() — callable-class dispatch + late-static scope (VM, #3197, #20251)
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

// #20251 — overridden parent method must not re-enter child
class FscOwnerA {
    public static function f(): string {
        return 'A';
    }
}
class FscOwnerB extends FscOwnerA {
    public static function f(): string {
        return forward_static_call(['FscOwnerA', 'f']);
    }
}
echo FscOwnerB::f(), "\n";

class FscLsbA {
    public static function f(): string {
        return static::class . '-A';
    }
}
class FscLsbB extends FscLsbA {
    public static function f(): string {
        return forward_static_call([FscLsbA::class, 'f']);
    }
}
echo FscLsbB::f(), "\n";

class FscArrA {
    public static function f(): string {
        return static::class . '-arr';
    }
}
class FscArrB extends FscArrA {
    public static function f(): string {
        return forward_static_call_array([FscArrA::class, 'f'], []);
    }
}
echo FscArrB::f(), "\n";
--EXPECT--
base
base
array_ok
Error
Cannot call forward_static_call() when no class scope is active
A
FscLsbB-A
FscArrB-arr
