<?php

declare(strict_types=1);

enum Status: string
{
    case Ok = 'ok';
    public function label(): string
    {
        return $this->name;
    }
}

echo Status::Ok->label(), "\n";

enum U
{
    case A;
    public function tag(): string
    {
        return 'unit';
    }
}

echo U::A->tag(), "\n";

try {
    Status::Ok->missing();
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
