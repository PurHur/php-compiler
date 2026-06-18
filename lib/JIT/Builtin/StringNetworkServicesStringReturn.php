<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmNetworkServices;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT bridges for getprotobynumber()/getservbyport() via NetworkServicesJitHelper (#9777).
 */
final class StringNetworkServicesStringReturn
{
    private const HELPER_PATH = '/ext/standard/NetworkServicesJitHelper.php';

    private const GETPROTOBYNUMBER_HELPER = 'PHPCompiler\\ext\\standard\\NetworkServicesJitHelper::getprotobynumber';

    private const GETSERVBYPORT_HELPER = 'PHPCompiler\\ext\\standard\\NetworkServicesJitHelper::getservbyport';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETPROTOBYNUMBER_HELPER,
        self::GETSERVBYPORT_HELPER,
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getprotobynumber');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (['__compiler_getprotobynumber', '__compiler_getservbyport'] as $name) {
                $fn = $context->module->getNamedFunction($name);
                if (null !== $fn) {
                    $context->registerFunction($name, $fn);
                }
            }

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementGetprotobynumberBridge($context);
        self::implementGetservbyportBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetprotobynumberBridge(Context $context): void
    {
        $abiName = '__compiler_getprotobynumber';
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('getprotobynumber_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::GETPROTOBYNUMBER_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue(self::emptyStringToNullSelect($context, $result));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetservbyportBridge(Context $context): void
    {
        $abiName = '__compiler_getservbyport';
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $i64, $strPtr);
        $fn = $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('getservbyport_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::GETSERVBYPORT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue(self::emptyStringToNullSelect($context, $result));
        $context->registerFunction($abiName, $fn);
    }

    private static function emptyStringToNullSelect(Context $context, Value $str): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        return $context->builder->select($isEmpty, $nullStr, $str);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after NetworkServicesJitHelper compile (#9777)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $template = (string) \file_get_contents($path);
        $tables = VmNetworkServices::buildJitTables();
        $source = \str_replace(
            [
                '__PHPC_NS_GETPROTOBYNUMBER_BODY__',
                '__PHPC_NS_GETSERVBYPORT_BODY__',
            ],
            [
                self::emitGetprotobynumberBody($tables['protoByNumber']),
                self::emitGetservbyportBody($tables['serviceByPort']),
            ],
            $template
        );
        $block = $runtime->parseAndCompile($source, 'NetworkServicesJitHelper.php');
        if (null === $block) {
            throw new \LogicException('NetworkServicesJitHelper.php parseAndCompile failed (#9777)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9777)');
            }
        }
    }

    /**
     * @param list<array{number: int, name: string}> $rows
     */
    private static function emitGetprotobynumberBody(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = '        if ($number === '.$row['number'].') {';
            $lines[] = "            return '".$row['name']."';";
            $lines[] = '        }';
        }
        $lines[] = "        return '';";

        return \implode("\n", $lines);
    }

    /**
     * @param list<array{port: int, protocol: string, name: string}> $rows
     */
    private static function emitGetservbyportBody(array $rows): string
    {
        $lines = [
            "        if ('' === \$protocol) {",
            "            return '';",
            '        }',
            '        $proto = strtolower($protocol);',
        ];
        foreach ($rows as $row) {
            $lines[] = '        if ($port === '.$row['port']." && \$proto === '".$row['protocol']."') {";
            $lines[] = "            return '".$row['name']."';";
            $lines[] = '        }';
        }
        $lines[] = "        return '';";

        return \implode("\n", $lines);
    }
}
