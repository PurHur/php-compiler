<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chmod() — VM via VmFs; JIT/AOT via ChmodJitHelper PHP (#15458). */
final class chmod_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chmod');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 2 (#30551).
        $this->requireExactArgCount($frame, 'chmod', 2);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'chmod');
        $mode = VmFilestatArg::parseChmodModeArgForFrame($frame, 1, 'chmod', 'permissions');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::chmod($path, $mode);
        if (!$ok && !VmStreamWrapperRegistry::isCustomProtocol($path)) {
            VmFilestatFailure::warnChmodFailed($frame, $path);
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30551 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'chmod', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        // Early return after compile-time null TypeError — no chmod invoke after abort (#31211 twin #31213).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitFilestatArg::lowerChmodMode($context, $args[1], 'chmod', 1, 'permissions');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'chmod_null_permissions_te_cont');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $modeI64 = JitFilestatArg::lowerChmodMode($context, $args[1], 'chmod', 1, 'permissions');
        $i32 = $context->getTypeFromString('int32');
        $mode = $context->builder->truncOrBitCast($modeI64, $i32);

        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chmod');

        return JitChmod::invoke($context, $path, $mode);
    }
}
