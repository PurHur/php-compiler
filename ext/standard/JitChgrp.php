<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ChownRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for chgrp()/lchgrp() via __compiler_chgrp (php-in-PHP ChownRuntime; #30167). */
final class JitChgrp
{
    /** @return Value true when __compiler_chgrp returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $groupVal, bool $lchgrp): Value
    {
        return ChownRuntime::invokeChgrp($context, $pathStr, $groupVal, $lchgrp);
    }
}
