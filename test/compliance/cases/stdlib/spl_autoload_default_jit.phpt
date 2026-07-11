--TEST--
stdlib spl_autoload() JIT lowering — no-op when class already defined (#4256, ext/spl/php_spl.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

class SplAutoloadJitAlreadyDefined
{
    public function tag(): string
    {
        return 'jit';
    }
}

spl_autoload('SplAutoloadJitAlreadyDefined');
spl_autoload('NoSuchJitClass', '.missing');
echo (new SplAutoloadJitAlreadyDefined())->tag(), "\n";
--EXPECT--
jit
