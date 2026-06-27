<?php

declare(strict_types=1);

class Invokable
{
    public function __invoke(): void
    {
        echo "invokable\n";
    }
}

class C
{
    public function method(): void
    {
        echo "method\n";
    }
}

register_shutdown_function(new Invokable());
register_shutdown_function([new C(), 'method']);
echo "before\n";
