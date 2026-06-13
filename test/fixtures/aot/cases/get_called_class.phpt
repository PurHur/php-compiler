--TEST--
AOT: get_called_class() late-static call-site class name (#4255, #3218)
--FILE--
<?php
class AotCalledClassC {
    public static function staticSelf(): void {
        echo get_called_class(), "\n";
    }
}
class AotCalledClassChild extends AotCalledClassC {}

AotCalledClassC::staticSelf();
AotCalledClassChild::staticSelf();
--EXPECT--
AotCalledClassC
AotCalledClassChild
