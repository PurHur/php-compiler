<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$files = [
    'bool' => __DIR__ . '/../test/repro/aot_ternary_bool.php',
    'var' => __DIR__ . '/../test/repro/aot_ternary_var_nullable.php',
];

$walk = function (PHPCompiler\Block $b, string $label) use (&$walk): void {
    echo "== $label hoisted=" . count($b->orig->hoistedOperands) . " ==\n";
    foreach ($b->opCodes as $op) {
        if ($op->type === PHPCompiler\OpCode::TYPE_ASSIGN) {
            $dest = $b->getOperand($op->arg2);
            $root = PHPCompiler\Block::cfgVarRoot($dest);
            $jitType = PHPCompiler\JIT\Variable::getTypeFromType($dest->type);
            echo '  assign dest slot=' . ($b->slotForOperand($dest) ?? 'null') . ' root='
                . (null === $root ? 'null' : (string) spl_object_id($root))
                . ' jitType=' . PHPCompiler\JIT\Variable::getStringType($jitType)
                . ' usages=' . count($dest->usages) . "\n";
        }
        if ($op->type === PHPCompiler\OpCode::TYPE_RETURN) {
            $ret = $b->getOperand($op->arg1);
            $root = PHPCompiler\Block::cfgVarRoot($ret);
            echo '  return slot=' . ($b->slotForOperand($ret) ?? 'null') . ' root='
                . (null === $root ? 'null' : (string) spl_object_id($root)) . "\n";
        }
        if ($op->type === PHPCompiler\OpCode::TYPE_JUMPIF) {
            $walk($op->block1, $label . '/if');
            $walk($op->block2, $label . '/else');
        }
        if ($op->type === PHPCompiler\OpCode::TYPE_JUMP && null !== $op->block1) {
            $walk($op->block1, $label . '/jump');
        }
    }
};

foreach ($files as $name => $path) {
    echo "\n######## $name ########\n";
    $runtime = new PHPCompiler\Runtime();
    $block = $runtime->parseAndCompile((string) file_get_contents($path), basename($path));
    foreach ($block->opCodes as $op) {
        if ($op->type === PHPCompiler\OpCode::TYPE_FUNCDEF) {
            $fn = $op->block1;
            echo "entry hoisted:\n";
            foreach ($fn->orig->hoistedOperands as $hoisted) {
                echo '  slot=' . ($fn->slotForOperand($hoisted) ?? 'null')
                    . ' type=' . PHPCompiler\JIT\Variable::getStringTypeFromType($hoisted->type) . "\n";
            }
            $walk($fn, 'f');
        }
    }
}
