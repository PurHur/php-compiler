<?php

declare(strict_types=1);

class C
{
    public function f(): array
    {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    }
}

$frame = (new C())->f();
echo isset($frame['class']) ? $frame['class'] : 'NO_CLASS', "\n";
echo isset($frame['type']) ? $frame['type'] : 'NO_TYPE', "\n";
echo $frame['function'], "\n";
