<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM;
use PHPLLVM\Value;

/**
 * Compile-time no-op for instance/static methods on classes not lowered into the bundle (#579).
 *
 * Returns null; does not invoke Zend or vendor code at runtime.
 */
final class ExternalMethod implements Call
{
    /** Per-call-site "already warned" flag counter, so a stub in a loop reports once, not per call. */
    private static int $warnSeq = 0;

    public function __construct(
        public readonly string $proxyName,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $context->recordExternalMethodStub($this->proxyName);
        self::emitStubReachedWarning($context, $this->proxyName);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    /**
     * Name the missing class when a stub is actually *reached* at runtime (#23540).
     *
     * The silent null only harms when executed. An unreached stub costs nothing — which is why
     * failing the build on a non-empty stub report is the wrong gate: a plain `echo` program carries
     * external stubs and runs correctly. The unacceptable case is the reached one: `var_dump(7)` in a
     * standalone binary dies with rc=134 and EMPTY stdout and stderr, because the null propagates
     * into a call that aborts. The user gets an exit code and nothing else to go on.
     *
     * This keeps the null, so behaviour is unchanged and nothing that works today starts failing. It
     * only adds a one-shot stderr line naming the symbol, which turns the whole #579 class from
     * silent into self-diagnosing. The flag is per call site so a stub inside a loop reports once.
     *
     * Opt-in via PHP_COMPILER_WARN_EXTERNAL_STUBS=1 while it is validated: there is no CI on lib/,
     * and this touches every external-stub site, so it defaults to off.
     */
    private static function emitStubReachedWarning(Context $context, string $proxyName): void
    {
        $flag = getenv('PHP_COMPILER_WARN_EXTERNAL_STUBS');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return;
        }
        if (null === $context->builder->getInsertBlock()) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        // fprintf is not declared in every module that reaches a stub — lookupFunction() throws
        // "Unable to lookup non-existing function fprintf" and takes the whole build down.
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );

        $once = $context->module->addGlobal($i8, 'phpc_stub_warned_'.(++self::$warnSeq));
        $once->setInitializer($i8->constInt(0, false));

        $seen = $context->builder->load($once);
        $firstTime = $context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $seen,
            $i8->constInt(0, false)
        );
        $warnBb = BasicBlockHelper::append($context, 'stub_warn_'.self::$warnSeq);
        $doneBb = BasicBlockHelper::append($context, 'stub_warn_done_'.self::$warnSeq);
        $context->builder->branchIf($firstTime, $warnBb, $doneBb);

        $context->builder->positionAtEnd($warnBb);
        $context->builder->store($i8->constInt(1, false), $once);
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            StringTriggerErrorJit::stderrFilePtr($context),
            $context->builder->pointerCast(
                $context->constantFromString(
                    'phpc: call to %s returned null — class not compiled into this module (#579)'."\n"
                ),
                $i8p
            ),
            $context->builder->pointerCast($context->constantFromString($proxyName), $i8p)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }
}
