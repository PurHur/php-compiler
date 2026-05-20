<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** file_put_contents() — VM only (issue #194). */
final class file_put_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('file_put_contents() requires two or three arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('file_put_contents() filename must be a string in this compiler build');
        }
        $flags = 0;
        if (3 === $argc) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('file_put_contents() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        $data = self::coerceData($dataVar);
        $written = VmFs::filePutContents($pathVar->toString(), $data, $flags);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('file_put_contents() is not implemented for JIT in this compiler build');
    }

    /**
     * @return string|list<string>
     */
    private static function coerceData(Variable $var): string|array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $lines = [];
            foreach ($var->toArray()->iterate(true) as $entry) {
                $lines[] = $entry->toString();
            }

            return $lines;
        }
        throw new \LogicException('file_put_contents() data must be a string or array in this compiler build');
    }
}
