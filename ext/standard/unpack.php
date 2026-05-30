<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** unpack() — binary decode (VM via host PHP; JIT/AOT via __compiler_unpack, issue #3188). */
final class unpack extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('unpack() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fmtVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('unpack() format must be a string in this compiler build');
        }
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \LogicException('unpack() data must be a string in this compiler build');
        }
        $offset = 0;
        if (3 === $argc) {
            $offsetVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offsetVar->type) {
                throw new \LogicException('unpack() offset must be an integer in this compiler build');
            }
            $offset = $offsetVar->toInt();
        }
        $result = VmPack::unpack($fmtVar->toString(), $dataVar->toString(), $offset);
        if (false === $result) {
            if (null !== $frame->vmContext) {
                $last = error_get_last();
                $message = 'unpack() failed';
                if (\is_array($last) && isset($last['message'])) {
                    $message = $last['message'];
                }
                $frame->vmContext->errors->triggerError(
                    $message,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(self::importResult($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return \call_user_func_array([JitUnpack::class, 'unpack'], array_merge([$context], $args));
    }

    /**
     * @param array<int|string, int|float|string> $result
     */
    private static function importResult(array $result): HashTable
    {
        $ht = new HashTable();
        foreach ($result as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } else {
                throw new \LogicException('unpack() result type not supported in this compiler build');
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add($key, $slot);
            }
        }

        return $ht;
    }
}
