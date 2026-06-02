<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** disk_total_space() — VM via host disk_total_space(); JIT/AOT via libc statvfs (php-src filestat.c, #3758). */
final class disk_total_space extends Internal
{
    public function __construct()
    {
        parent::__construct('disk_total_space');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('disk_total_space() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = null;
        if (1 === $argc) {
            $path = self::optionalPath($frame->calledArgs[0]->resolveIndirect());
        }
        $result = VmFs::diskTotalSpace($path);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->float($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('disk_total_space() accepts at most one argument in this compiler build');
        }
        $path = JitDiskPath::lower($context, $args[0] ?? null, 'disk_total_space() path');

        return JitStat::pathDiskTotalSpaceBoxed($context, $path);
    }

    private static function optionalPath(Variable $v): ?string
    {
        if (Variable::TYPE_NULL === $v->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('disk_total_space() path must be a string or null in this compiler build');
        }

        return $v->toString();
    }
}
