<?php

declare(strict_types=1);

class C
{
    private const PRIVATE_CONST = 1;
    protected const PROTECTED_CONST = 2;
    public const PUBLIC_CONST = 3;
}

class P
{
    private const PARENT_PRIVATE = 1;
}

class Child extends P
{
    public function fetchParentPrivate(): void
    {
        try {
            echo parent::PARENT_PRIVATE;
            echo " BUG parent private\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}

enum E: int
{
    case A = 1;
    private const ENUM_PRIVATE = 9;
}

try {
    echo C::PRIVATE_CONST;
    echo " BUG private global\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    echo C::PROTECTED_CONST;
    echo " BUG protected global\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

echo C::PUBLIC_CONST, "\n";

try {
    echo E::ENUM_PRIVATE;
    echo " BUG enum private\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

(new Child())->fetchParentPrivate();
