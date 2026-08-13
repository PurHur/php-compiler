<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chgrp() — VM via VmFs; JIT/AOT via __compiler_chgrp (php-src ext/standard/filestat.c). */
final class chgrp_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chgrp');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 2 (#30554).
        $this->requireExactArgCount($frame, 'chgrp', 2);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'chgrp');
        $groupVar = VmFilestatArg::requireIntOrStringArgForFrame($frame, 1, 'chgrp', 'group');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::chgrp($path, $groupVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'chgrp');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'chgrp', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chgrp');
        $groupPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'chgrp', 1, 'group');

        return JitChgrp::invoke($context, $path, $groupPtr, false);
    }
}
