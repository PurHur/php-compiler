<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$jitFsGlob = <<<'PHP'
<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for glob() and scandir(); paths from libc, list built in JIT (issue #1153). */
final class JitFsGlob
{
    private static int $seq = 0;

    public static function glob(Context $context, Value $patternStr, Value $flagsI32): Value
    {
        return self::collectList($context, '__phpc_glob_vec', $patternStr, $flagsI32, 'glob');
    }

    public static function scandir(Context $context, Value $pathStr, Value $sortI32): Value
    {
        return self::collectList($context, '__phpc_scandir_vec', $pathStr, $sortI32, 'scandir');
    }

    private static function collectList(Context $context, string $collectFn, Value $argStr, Value $argI32, string $id): Value
    {
        $tag = $id.(string) ++self::$seq;
        $i8pp = $context->getTypeFromString('int8**');
        $itemsSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($i8pp->constNull(), $itemsSlot);
        $count = $context->builder->call($context->lookupFunction($collectFn), $argStr, $argI32, $itemsSlot);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $count, $i32->constInt(0, false));
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $buildBlock = BasicBlockHelper::append($context, $tag.'_build');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $buildBlock);
        $context->builder->positionAtEnd($failBlock);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($buildBlock);
        $ht = self::buildHashtableFromItems($context, $itemsSlot, $count, $tag);
        $context->builder->call($context->lookupFunction('__phpc_strvec_free'), $context->builder->load($itemsSlot), $count);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($okPtr, $buildBlock);

        return $result;
    }

    private static function buildHashtableFromItems(Context $context, Value $itemsSlot, Value $count, string $tag): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $stringInit = $context->lookupFunction('__string__init');
        $strlenFn = $context->lookupFunction('strlen');
        $loopHead = BasicBlockHelper::append($context, $tag.'_head');
        $loopBody = BasicBlockHelper::append($context, $tag.'_body');
        $loopDone = BasicBlockHelper::append($context, $tag.'_ht_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $countSized = $context->builder->truncOrBitCast($count, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $countSized);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $items = $context->builder->load($itemsSlot);
        $cstr = $context->builder->load($context->builder->inBoundsGep($items, $i));
        $len = $context->builder->call($strlenFn, $cstr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $cstrCast = $context->builder->pointerCast($cstr, $i8p);
        $str = $context->builder->call($stringInit, $lenI64, $cstrCast);
        $context->builder->call($setString, $ht, $i, $str);
        $context->builder->store($context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopDone);
        BasicBlockHelper::branchToFreshContinue($context, $tag.'_continue');

        return $ht;
    }
}
PHP;

file_put_contents($root.'/ext/standard/JitFsGlob.php', $jitFsGlob);

foreach (['glob_.php' => 'glob', 'scandir.php' => 'scandir'] as $file => $fn) {
    $path = $root.'/ext/standard/'.$file;
    $text = file_get_contents($path);
    if (!str_contains($text, 'use PHPCompiler\\JIT\\JitLongArg;')) {
        $text = str_replace(
            'use PHPCompiler\\JIT\\Variable as JITVariable;',
            "use PHPCompiler\\JIT\\JitLongArg;\nuse PHPCompiler\\JIT\\Variable as JITVariable;",
            $text
        );
    }
    $argLabel = 'glob' === $fn ? 'glob() flags' : 'scandir() sorting_order';
    $pathLabel = 'glob' === $fn ? 'glob() argument #1' : 'scandir() argument #1';
    $pattern = '/\$i32 = \$context->getTypeFromString\(\'int32\'\);\s*\$flags = \$i32->constInt\(0, false\);/s';
    if ('scandir' === $fn) {
        $text = preg_replace('/\$sort = \$i32->constInt\(0, false\);/', '$sort = $i32->constInt(0, false);', $text, 1);
    }
    $old = <<<'PHP'
        $i32 = $context->getTypeFromString('int32');
        $flags = $i32->constInt(0, false);
PHP;
    if ('scandir' === $fn) {
        $old = str_replace('$flags', '$sort', $old);
    }
    // Replace call() tail generically
    $text = preg_replace(
        '/if \(2 === \$argc\) \{.*?loadValue\(\$args\[1\]\);\s*\}/s',
        "if (2 === \$argc) {\n            if (JITVariable::TYPE_INTEGER !== \$args[1]->type\n                && JITVariable::TYPE_NATIVE_LONG !== \$args[1]->type) {\n                throw new \\LogicException('{$fn}() second argument must be an integer in this compiler build');\n            }\n            \$".('glob' === $fn ? 'flags' : 'sort')." = \$context->builder->truncOrBitCast(\n                JitLongArg::lower(\$context, \$args[1], '{$argLabel}'),\n                \$i32\n            );\n        }",
        $text,
        1
    );
    $text = preg_replace(
        '/\$this->jitString\(\$context, \$args\[0\], \'[^\']+\'\);\s*return JitFsGlob::'.$fn.'\(\$context, \$context->helper->loadValue\(\$args\[0\]\), \$'.('glob' === $fn ? 'flags' : 'sort').'\);/s',
        "\$".('glob' === $fn ? 'pattern' : 'path')." = \$this->jitString(\$context, \$args[0], '{$pathLabel}');\n\n        return JitFsGlob::{$fn}(\$context, \$".('glob' === $fn ? 'pattern' : 'path').", \$".('glob' === $fn ? 'flags' : 'sort').');',
        $text,
        1
    );
    file_put_contents($path, $text);
}

$cPath = $root.'/lib/AOT/runtime/phpc_fs_dir.c';
$c = file_get_contents($cPath);
if (!str_contains($c, '__phpc_glob_vec')) {
    $insert = file_get_contents($root.'/script/apply-1153-phpc_fs_dir_snippet.c');
    $c = str_replace('__hashtable__ *__phpc_glob(__string__ *pattern, int flags)', $insert.'__hashtable__ *__phpc_glob(__string__ *pattern, int flags)', $c);
    file_put_contents($cPath, $c);
}

$tPath = $root.'/lib/JIT/Builtin/Type.php';
$t = file_get_contents($tPath);
if (!str_contains($t, '__phpc_glob_vec')) {
    $t = str_replace(
        "\$htPtr = \$this->context->getTypeFromString('__hashtable__*');\n",
        "\$htPtr = \$this->context->getTypeFromString('__hashtable__*');\n        \$i8ppPtr = \$this->context->getTypeFromString('int8**');\n",
        $t
    );
    $t = str_replace(
        '        $fnGlob = $this->context->module->addFunction(',
        <<<'PHP'
        $fnGlobVec = $this->context->module->addFunction(
            '__phpc_glob_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8ppPtr)
        );
        $this->context->registerFunction('__phpc_glob_vec', $fnGlobVec);
        $fnScandirVec = $this->context->module->addFunction(
            '__phpc_scandir_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8ppPtr)
        );
        $this->context->registerFunction('__phpc_scandir_vec', $fnScandirVec);
        $fnStrvecFree = $this->context->module->addFunction(
            '__phpc_strvec_free',
            $this->context->context->functionType($void, false, $i8ppPtr, $i32)
        );
        $this->context->registerFunction('__phpc_strvec_free', $fnStrvecFree);
        $fnGlob = $this->context->module->addFunction(
PHP,
        $t
    );
    file_put_contents($tPath, $t);
}

$pPath = $root.'/lib/JIT/SelfHostBuiltinPolicy.php';
$p = file_get_contents($pPath);
if (!str_contains($p, "'glob' => 'filesystem'")) {
    $p = str_replace(
        "'realpath' => 'filesystem',",
        "'realpath' => 'filesystem',\n        'glob' => 'filesystem', 'scandir' => 'filesystem',",
        $p
    );
    file_put_contents($pPath, $p);
}

echo "applied\n";
