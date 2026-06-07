<?php

class C {
    public function __toString(): string
    {
        return 'ok';
    }
}

function needsStringable(Stringable $s): void
{
    echo $s, "\n";
}

var_export(new C() instanceof Stringable);
echo "\n";
needsStringable(new C());
