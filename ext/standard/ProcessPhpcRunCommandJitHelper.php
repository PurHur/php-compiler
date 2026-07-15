<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * phpc_run_command for compiled JIT/AOT embed modules (#9337, #10492).
 *
 * Split from {@see ProcessJitHelper} so exec/passthru/system capture helpers compile
 * without nested-JITing HashTable::iterateKeyed paths (see ParseStrNativeJitHelper).
 */
final class ProcessPhpcRunCommandJitHelper
{
    /** @return HashTable|null null when command fails */
    public static function phpcRunCommandArgv(?string $command, ?HashTable $env): ?HashTable
    {
        if (null === $command || '' === $command) {
            return null;
        }

        $envArray = null;
        if (null !== $env) {
            $envArray = [];
            foreach ($env->exportKeyValuePairs(true) as [$keyVar, $valVar]) {
                if (Variable::TYPE_STRING !== $keyVar->type || Variable::TYPE_STRING !== $valVar->type) {
                    continue;
                }
                $envArray[$keyVar->toString()] = $valVar->toString();
            }
        }

        $captured = VmPhpcRunCommandNative::run($command, $envArray);
        if (null === $captured) {
            return null;
        }

        $ht = new HashTable();
        $codeVar = new Variable();
        $codeVar->int($captured['code']);
        $ht->add('code', $codeVar);
        $stdoutVar = new Variable();
        $stdoutVar->string($captured['stdout']);
        $ht->add('stdout', $stdoutVar);
        $stderrVar = new Variable();
        $stderrVar->string($captured['stderr']);
        $ht->add('stderr', $stderrVar);

        return $ht;
    }
}
