<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_bin2base64() — binary to base64 variant (php-src ext/sodium/libsodium.c; #20675). */
final class sodium_bin2base64 extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_bin2base64');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        // Z_PARAM_STR $string — null TypeError on 8.4 forward profile (peer #20196).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'sodium_bin2base64', 0, 'string');
        $id = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'sodium_bin2base64', 2, 'id');
        $result = VmSodium::bin2base64($string, $id);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
