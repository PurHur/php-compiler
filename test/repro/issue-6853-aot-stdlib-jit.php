<?php

class FscBase {
    public static function target(): string {
        return 'base';
    }
    public static function forwarder(): string {
        return forward_static_call([self::class, 'target']);
    }
}
class FscChild extends FscBase {
    public static function target(): string {
        return 'child';
    }
}

class CalledClassC {
    public static function staticSelf(): void {
        echo get_called_class(), "\n";
    }
}
class CalledClassChild extends CalledClassC {}

echo FscChild::forwarder(), "\n";
CalledClassChild::staticSelf();
echo diskfreespace(sys_get_temp_dir()) !== false ? "disk_ok\n" : "disk_fail\n";
