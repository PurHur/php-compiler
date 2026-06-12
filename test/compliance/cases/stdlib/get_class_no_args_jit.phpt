--TEST--
stdlib get_class() — JIT zero-argument form in object scope (#4092)
--JIT--
--FILE--
<?php
class GetClassJitC {
    public function instanceSelf(): void {
        echo get_class(), "\n";
    }
}
(new GetClassJitC())->instanceSelf();
--EXPECT--
GetClassJitC
