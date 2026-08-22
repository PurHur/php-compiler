<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * DOMElement::hasAttributeNS() — user-script AOT live Attr cache (#32398, #33762).
 *
 * Boxes a real bool (Zend RETURN_BOOL); writeLong 0/1 made var_dump show int (#33762).
 */
final class DomElementHasAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattrns_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::hasAttributeNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        // Prefer isNullConstant over compileTimeString (stale cts on null args; #33532).
        $nsLit = self::compileTimeNamespace($args[1]);
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $present = null !== $nsLit && null !== $localLit
            && DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsLit, $localLit);

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($present ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function compileTimeNamespace(Variable $arg): ?string
    {
        if (Variable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return '';
        }

        return JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
    }
}
