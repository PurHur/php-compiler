--TEST--
stdlib spl_autoload_call() JIT invokes registered closure autoload (issue #3486)
--FILE--
<?php
$loaded = false;
spl_autoload_register(function (string $class) use (&$loaded): void {
    if ('LazyClass' !== $class) {
        return;
    }
    class LazyClass
    {
        public function id(): int
        {
            return 7;
        }
    }
    $loaded = true;
});
spl_autoload_call('LazyClass');
var_export($loaded);
echo "\n";
echo (new LazyClass())->id(), "\n";
--EXPECT--
true
7
