<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FilterExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * filter surfaces for lib/JIT Builtin Filter* (#36204).
 *
 * php-src: ext/filter/filter.c — php_filter_var_array / logical_filters.
 * Registered from {@see Module::jitInit} so Builtin files do not import ext/filter.
 */
final class JitFilterExtensionHooksFacade implements FilterExtensionHooks
{
    public function validateInt(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateInt($context, $value);
    }

    public function validateBoolean(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateBoolean($context, $value);
    }

    public function validateFloat(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateFloat($context, $value);
    }

    public function validateEmail(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateEmail($context, $value);
    }

    public function validateUrl(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateUrl($context, $value);
    }

    public function validateIp(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateIp($context, $value);
    }

    public function validateMac(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateMac($context, $value);
    }

    public function validateDomain(Context $context, JITVariable $value): Value
    {
        return JitFilter::validateDomain($context, $value);
    }

    public function inputTypeParamName(string $function): string
    {
        return VmFilter::inputTypeParamName($function);
    }

    public function tryPhpInputFilterInt(VmVariable $var): ?int
    {
        return VmFilter::tryPhpInputFilterInt($var);
    }

    public function filterVarArrayConst(
        HashTable $data,
        HashTable|int|null $definition,
        int $addEmpty
    ): ?HashTable {
        $frame = new Frame(new filter_var_array(), null, null);

        return VmFilter::filterVarArray($data, $definition, $addEmpty, $frame);
    }
}
