<?php
/**
 * Repro guard for #22642 r15: ASSIGN with scope-missing slots must not TypeError
 * inside IncludeBindingJitHelper::lastAssignVariableForName.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\ext\standard\IncludeBindingJitHelper;

$block = new Block(null);
$assign = new OpCode(OpCode::TYPE_ASSIGN);
$assign->arg1 = 42;
$assign->arg2 = 43;
$block->opCodes[] = $assign;
$block->nOpCodes = 1;

$runtime = new Runtime();
$context = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

$result = IncludeBindingJitHelper::lastAssignVariableForName($context, $block, 'x');
echo null === $result ? "ok null\n" : "unexpected\n";
