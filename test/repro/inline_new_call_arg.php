<?php

declare(strict_types=1);

final class LiteralStub
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}

function foldLiteral(LiteralStub $operand): string
{
    return $operand->value;
}

echo foldLiteral(new LiteralStub('templates')) === 'templates' ? '1' : '0';
