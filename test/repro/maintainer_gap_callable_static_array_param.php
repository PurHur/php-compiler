<?php

declare(strict_types=1);

class CallableStaticArrayParamReproC
{
    public static function m(string $s): void
    {
        echo $s;
    }
}

function callable_static_array_param_accept(callable $c): void
{
    $c('hi');
}

if (!is_callable([CallableStaticArrayParamReproC::class, 'm'])) {
    fwrite(STDERR, "is_callable static array failed\n");
    exit(1);
}

ob_start();
callable_static_array_param_accept([CallableStaticArrayParamReproC::class, 'm']);
$out = ob_get_clean();
if ('hi' !== $out) {
    fwrite(STDERR, "expected hi, got {$out}\n");
    exit(1);
}

echo "ok\n";
