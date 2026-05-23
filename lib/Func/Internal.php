<?php
declare(strict_types=1);
namespace PHPCompiler\Func;
use PHPCompiler\Frame; use PHPCompiler\Func; use PHPCompiler\Handler; use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context as JITContext; use PHPCompiler\JIT\JitBoolArg; use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg; use PHPCompiler\JIT\Variable as JITVariable; use PHPCompiler\VM\Context; use PHPLLVM\Value;
abstract class Internal extends Func implements Handler, Call {
    public function __construct(string $name = null) { if (null === $name) { $parts = explode("\\", get_class($this)); $name = end($parts); } parent::__construct($name); }
    public function getFrame(Context $context, ?Frame $frame = null): Frame { return new Frame($this, null, null); }
    protected function jitString(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitStringArg::lower($context, $arg, $contextLabel); }
    protected function jitLong(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitLongArg::lower($context, $arg, $contextLabel); }
    protected function jitBool(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitBoolArg::lower($context, $arg, $contextLabel); }
    protected function stringDataPtr(JITContext $context, Value $strPtr): Value {
        $structName = $strPtr->typeOf()->getElementType()->getName(); $off = $context->structFieldMap[$structName]["value"];
        return $context->builder->structGep($strPtr, $off);
    }
}
