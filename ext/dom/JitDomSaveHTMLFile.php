<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitFilePutContents;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::saveHTMLFile() (#35549 / #18268).
 *
 * NestedJIT {@see VmDom::saveHTMLFile} is stubbed under thin AOT (#579), so compose the
 * working user-script {@see JitDomSaveHTML} path with {@see JitFilePutContents}
 * (peer {@see JitDomSave} / #35546).
 *
 * php-src: ext/dom/php_dom.c zim_DOMDocument_saveHTMLFile
 */
final class JitDomSaveHTMLFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_savehtmlfile_invoke');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::saveHTMLFile',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $htmlValuePtr = JitDomSaveHTML::invoke($context, $args[0]);
        $htmlStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $htmlValuePtr
        );
        $path = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::saveHTMLFile',
            0,
            'filename'
        );
        StringFilePutContents::ensureStandaloneBodies($context);
        $flags = $context->context->int64Type()->constInt(0, false);

        return JitFilePutContents::invoke($context, $path, $htmlStr, $flags);
    }
}
