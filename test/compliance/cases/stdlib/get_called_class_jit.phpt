--TEST--
stdlib get_called_class() — JIT late-static call-site class name (#4255, #3218)
--JIT--
--FILE--
<?php
class GetCalledClassJitC {
    public static function staticSelf(): void {
        echo get_called_class(), "\n";
    }
    public function instanceSelf(): void {
        echo get_called_class(), "\n";
    }
}
class GetCalledClassJitChild extends GetCalledClassJitC {}

GetCalledClassJitC::staticSelf();
GetCalledClassJitChild::staticSelf();
(new GetCalledClassJitChild())->instanceSelf();
--EXPECT--
GetCalledClassJitC
GetCalledClassJitChild
GetCalledClassJitChild
