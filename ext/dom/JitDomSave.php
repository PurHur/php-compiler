<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitFilePutContents;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::save() (#35546 / #18435).
 *
 * NestedJIT DomRegistry object ids are zero across helper boundaries under thin AOT,
 * so compose the working user-script {@see JitDomSaveXML} path with
 * {@see JitFilePutContents} (peer {@see JitDomC14NFile}).
 *
 * php-src: ext/dom/php_dom.c zim_DOMDocument_save — write saveXML() bytes to $filename.
 */
final class JitDomSave
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_save_invoke');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMDocument::save',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        // options are accepted then ignored — matches VmDom::save / php-src dump flags (#18435).
        if (isset($args[2])) {
            JitLongArg::lower(
                $context,
                $args[2],
                'DOMDocument::save(): Argument #2 ($options)'
            );
        }

        $xmlValuePtr = JitDomSaveXML::invoke($context, $args[0]);
        $xmlStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $xmlValuePtr
        );
        $path = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::save',
            0,
            'filename'
        );
        StringFilePutContents::ensureStandaloneBodies($context);
        $flags = $context->context->int64Type()->constInt(0, false);

        return JitFilePutContents::invoke($context, $path, $xmlStr, $flags);
    }
}
