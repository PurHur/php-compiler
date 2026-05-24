--TEST--
stdlib spl_autoload_register() VM invokes callback on unknown class
--FILE--
<?php
function autoload_demo(string $class): void
{
    if ('Demo' === $class) {
        class Demo
        {
            public function id(): int
            {
                return 9;
            }
        }
    }
}
spl_autoload_register('autoload_demo');
echo (new Demo())->id(), "\n";
--EXPECT--
9
