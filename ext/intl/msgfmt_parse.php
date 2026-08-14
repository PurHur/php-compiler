<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * msgfmt_parse() — procedural MessageFormatter::parse
 * (php-src msgfmt_format.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_parse() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $formatter = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $formatter->type
            || !VmMessageFormatter::isFormatterObject($formatter->toObject())) {
            throw new \TypeError(\sprintf(
                'msgfmt_parse(): Argument #1 ($formatter) must be of type MessageFormatter, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($formatter)
            ));
        }
        $source = VmMessageFormatter::coerceSourceArgFromFrame($frame, 1, 'msgfmt_parse', 1);
        $result = VmMessageFormatter::parse($formatter->toObject(), $source);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmMessageFormatter::valuesToHashTable($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_parse() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
