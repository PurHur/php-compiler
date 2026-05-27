<?php

declare(strict_types=1);

/**
 * Global phpc_run_command() for Zend bootstrap and M3 native emit before AOT link (#2769, #2779).
 */

require_once __DIR__.'/LinkerProcessPolyfill.php';

if (!\function_exists('phpc_run_command')) {
    /**
     * @param array<string, string>|null $env
     *
     * @return array{code:int,stdout:string,stderr:string}|null
     */
    function phpc_run_command(string $command, ?array $env = null): ?array
    {
        return \PHPCompiler\AOT\LinkerProcessPolyfill::run($command, $env);
    }
}
