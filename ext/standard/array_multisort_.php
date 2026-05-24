<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_multisort() for packed list arrays (subset of PHP; issue #1212).
 *
 * VM: two or more homogeneous string/int arrays, optional trailing SORT_ASC (4) or SORT_DESC (3).
 * JIT/AOT: deferred (#1212); use bin/vm.php or bin/serve.php.
 */
final class array_multisort_ extends Internal
{
    private const SORT_DESC = 3;
    private const SORT_ASC = 4;

    public function __construct()
    {
        parent::__construct('array_multisort');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('array_multisort() requires at least one argument');
        }
        $tables = [];
        $descending = false;
        foreach ($frame->calledArgs as $arg) {
            $resolved = $arg->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                $tables[] = $resolved->toArray();

                continue;
            }
            if (Variable::TYPE_INTEGER === $resolved->type) {
                $flag = $resolved->toInt();
                if (self::SORT_DESC === $flag) {
                    $descending = true;
                } elseif (self::SORT_ASC === $flag) {
                    $descending = false;
                } else {
                    throw new \LogicException(
                        'array_multisort() only supports SORT_ASC and SORT_DESC flags in this compiler build'
                    );
                }

                continue;
            }
            throw new \LogicException(
                'array_multisort() arguments must be arrays or SORT_* flags in this compiler build'
            );
        }
        if ([] === $tables) {
            throw new \LogicException('array_multisort() requires at least one array argument');
        }
        VmArrayMultisort::apply($tables, $descending);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_multisort() is not implemented for JIT in this compiler build');
    }
}
