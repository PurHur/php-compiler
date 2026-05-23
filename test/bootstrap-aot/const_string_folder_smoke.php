<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: ConstStringFolder literalStringValue via fold() (real LLVM lowering).
 */

final class LiteralStub
{
    public $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}

/** Mirrors ConstStringFolder::literalStringValue / fold() for literal operands. */
function foldLiteral(LiteralStub $operand): string
{
    return $operand->value;
}

echo foldLiteral(new LiteralStub('templates')) === 'templates' ? '1' : '0';

$path = __DIR__.'/deploy_path_data/templates/marker.php';
$resolved = realpath($path);
echo is_string($resolved) && is_file($resolved) ? '1' : '0';
