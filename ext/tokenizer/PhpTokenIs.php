<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** PhpToken::is(int|string|array $kind): bool — VM (#6794). */
final class PhpTokenIs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('is');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PhpToken::is() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmPhpToken::requirePhpToken($frame->calledArgs[0], 'PhpToken::is', 0, 'object');
        $kind = $frame->calledArgs[1]->resolveIndirect();
        $frame->returnVar->bool(VmPhpToken::matchesKind($entry, self::unwrapKind($kind)));
    }

    /**
     * @return int|string|list<int|string>
     */
    private static function unwrapKind(Variable $kind): int|string|array
    {
        if (Variable::TYPE_ARRAY === $kind->type) {
            $items = [];
            foreach ($kind->toArray()->iterate(true) as $item) {
                $items[] = self::unwrapScalarKind($item);
            }

            return $items;
        }

        return self::unwrapScalarKind($kind);
    }

  /**
     * @return int|string
     */
    private static function unwrapScalarKind(Variable $kind): int|string
    {
        if (Variable::TYPE_INTEGER === $kind->type || Variable::TYPE_FLOAT === $kind->type) {
            return $kind->toInt();
        }
        if (Variable::TYPE_STRING === $kind->type) {
            return $kind->toString();
        }

        throw new \TypeError('PhpToken::is(): Argument #1 ($kind) must be of type array|string|int');
    }
}
