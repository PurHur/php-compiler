<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** brotli_uncompress_add() — streaming decompress chunk (kjdev/php-ext-brotli; #27856). */
final class brotli_uncompress_add extends Internal
{
    public function __construct()
    {
        parent::__construct('brotli_uncompress_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'brotli_uncompress_add() expects 2 to 3 arguments, '.$argc.' given'
            );
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'brotli_uncompress_add', 1, 'data');
        $mode = VmBrotliContext::OP_FLUSH;
        if (3 === $argc) {
            $modeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \TypeError(\sprintf(
                    'brotli_uncompress_add(): Argument #3 ($mode) must be of type int, %s given',
                    match ($modeVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_DOUBLE => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $modeVar->toObject()->class->name,
                        default => 'mixed',
                    }
                ));
            }
            $mode = $modeVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBrotliContext::uncompressAdd($frame->calledArgs[0], $data, $mode);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('brotli_uncompress_add() is VM-only in this compiler build (#27856)');
    }
}
