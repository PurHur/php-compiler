<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmParseStr;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;
use ArgumentCountError;

/**
 * mb_parse_str() — form-urlencoded parse with mbstring HTTP-input conversion (#20015).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_parse_str)
 * php-src: ext/mbstring/mbstring.stub.php — mb_parse_str(string $string, &$result): bool
 */
final class mb_parse_str extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_parse_str');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new ArgumentCountError(\sprintf(
                'mb_parse_str() expects exactly 2 arguments, %d given',
                $argc
            ));
        }

        $encoded = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'mb_parse_str',
            0,
            'string'
        );
        $parsed = VmMbParseStr::parse($encoded);
        MbstringState::setHttpInputIdentify($parsed['detected']);

        $resultArg = $frame->calledArgs[1];
        $ht = new HashTable();
        VmParseStr::mergeInto($ht, $parsed['params']);
        $replacement = new Variable(Variable::TYPE_ARRAY);
        $replacement->array($ht);
        $resultArg->copyFrom($replacement);

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($parsed): void {
            $ret->bool($parsed['ok']);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('mb_parse_str() expects exactly 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitMbParseStr::parse($context, $args[0], $args[1]);
    }
}
