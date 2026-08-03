--TEST--
forward_static_call() named descendant uses callee class as called_scope (#27140)
--FILE--
<?php
class FscDescA {
    public static function who(): string {
        return static::class;
    }
    public static function viaB(): string {
        return forward_static_call([FscDescB::class, 'who']);
    }
    public static function viaParent(): string {
        return forward_static_call('parent::who');
    }
}
class FscDescB extends FscDescA {
    public static function viaB(): string {
        return forward_static_call([FscDescB::class, 'who']);
    }
    public static function viaParent(): string {
        return forward_static_call('parent::who');
    }
}
class FscDescC extends FscDescB {
    public static function viaA(): string {
        return forward_static_call([FscDescA::class, 'who']);
    }
}
echo FscDescA::viaB(), "\n";
echo FscDescB::viaB(), "\n";
echo FscDescC::viaA(), "\n";
echo FscDescB::viaParent(), "\n";
--EXPECT--
FscDescB
FscDescB
FscDescC
FscDescB
