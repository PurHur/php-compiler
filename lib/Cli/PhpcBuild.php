<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

/**
 * phpc build --project orchestration and actionable AOT failure hints (issue #643, #568).
 */
final class PhpcBuild
{
    private const ISSUE_USER_CLASS = 'https://github.com/PurHur/php-compiler/issues/568';

    private const ISSUE_ROADMAP = 'https://github.com/PurHur/php-compiler/issues/78';

    /**
     * @param list<string> $php Host PHP argv prefix (binary + -d flags)
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function runCompile(
        array $php,
        string $repoRoot,
        string $compileScript,
        string $cwd,
        array $compileArgv
    ): array {
        $cmd = array_merge($php, [$compileScript], $compileArgv);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($proc)) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start: '.implode(' ', $cmd)."\n",
            ];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }

    /**
     * True when compile/link stderr matches the known user-class AOT gap (#568).
     */
    public static function isUserClassAotBlocked(string $stderr): bool
    {
        if ('' === $stderr) {
            return false;
        }
        $needles = [
            'Unsupported native type __object__',
            '__object__',
            'user-class',
            'user class',
            'router::',
            '::render',
            'Other class body types are not jittable',
            'JIT opcode',
            'LogicException',
            'does not have terminator',
            'Function return type does not match operand type of return inst',
            'LLVMAbstract\\Module->verify',
        ];
        foreach ($needles as $needle) {
            if (str_contains($stderr, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function formatUserClassTrailer(): string
    {
        return implode("\n", [
            '',
            '---',
            'phpc build --project: user-defined classes are not yet linkable in native AOT (#568).',
            'Tracking: #568 — '.self::ISSUE_USER_CLASS.' (native user-class object model)',
            'Roadmap: '.self::ISSUE_ROADMAP,
            'Next steps:',
            '  ./phpc lint --all <project>',
            '  ./phpc serve 127.0.0.1:8080 <project>   # VM / dev server',
            '  make miniwebapp-gates                    # lint + serve gates (no AOT link)',
            'Use phpc build --verbose to keep full LLVM stderr above this message.',
            '---',
            '',
        ]);
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $result
     */
    public static function emitBuildOutput(array $result, bool $verbose): void
    {
        if ('' !== $result['stdout']) {
            fwrite(STDOUT, $result['stdout']);
            if (!str_ends_with($result['stdout'], "\n")) {
                fwrite(STDOUT, "\n");
            }
        }
        if ('' !== $result['stderr']) {
            fwrite(STDERR, $result['stderr']);
            if (!str_ends_with($result['stderr'], "\n")) {
                fwrite(STDERR, "\n");
            }
        }
        if (0 !== $result['exit'] && self::isUserClassAotBlocked($result['stderr'])) {
            fwrite(STDERR, self::formatUserClassTrailer());
        }
    }
}
