<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;
use PHPCompiler\ext\tokenizer\VmTokenizer;
use PHPUnit\Framework\TestCase;

/**
 * @group vm_tokenizer_native
 */
final class VmTokenizerNativeTest extends TestCase
{
    public function test_issue_repro_echo_one(): void
    {
        $tokens = VmTokenizer::tokenize('<?php echo 1;');
        self::assertGreaterThan(1, \count($tokens));
        self::assertTrue(\is_array($tokens[1]));
        self::assertSame(TokenConstantsData::nameToId()['T_ECHO'], $tokens[1][0]);
    }

    public function test_native_matches_zend_on_bootstrap_snippets(): void
    {
        if (!\function_exists('token_get_all')) {
            self::markTestSkipped('host ext-tokenizer not loaded');
        }

        $samples = [
            '<?php echo 1;',
            '<?php declare(strict_types=1); class A {}',
            '<?php enum E { case A, B; }',
            '<?php #[Attribute] class A {}',
            '<?php function f(): void {}',
            "<?php \$x = 1; // comment\n",
            '<?php namespace Foo\\Bar;',
            '<?php use Foo\\Bar\\Baz;',
            '<?php 0xFF; 0b101; 1_000;',
            '<?php yield from $x;',
            '<?php (int)$x;',
            '<?php $a = "hello";',
            '<?php $a = "hello $world";',
            "<?php \$a = <<<'EOF'\nline\nEOF;",
        ];

        foreach ($samples as $sample) {
            self::assertSame(
                $this->normalizeTokens(\token_get_all($sample)),
                $this->normalizeTokens(VmTokenizer::tokenize($sample)),
                $sample
            );
        }
    }

    public function test_token_name_for_echo(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\tokenizer\token_name();
        $frame = $fn->getFrame($runtime->vmContext);
        $type = new \PHPCompiler\VM\Variable();
        $type->int(TokenConstantsData::nameToId()['T_ECHO']);
        $frame->calledArgs = [$type];
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        self::assertSame('T_ECHO', $frame->returnVar->toString());
    }

    public function test_issue_13896_null_byte_after_dollar(): void
    {
        $src = "<?php \$\0 = 1;";
        $tokens = LanguageScanner::tokenize($src);
        self::assertSame('$', $tokens[1]);
        self::assertTrue(\is_array($tokens[2]));
        self::assertSame(TokenConstantsData::nameToId()['T_BAD_CHARACTER'], $tokens[2][0]);
    }

    public function test_bare_dollar_before_number_is_literal(): void
    {
        $tokens = LanguageScanner::tokenize('<?php $123;');
        self::assertSame('$', $tokens[1]);
        self::assertTrue(\is_array($tokens[2]));
        self::assertSame(TokenConstantsData::nameToId()['T_LNUMBER'], $tokens[2][0]);
        self::assertSame('123', $tokens[2][1]);
    }

    public function test_vm_token_get_all_issue_repro(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$t = token_get_all('<?php echo 1;');
echo $t[1][0] === T_ECHO ? "ok\n" : "fail\n";
echo token_name(T_ECHO), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'token_repro.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\nT_ECHO\n", ob_get_clean());
    }

    /**
     * @param list<int|string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return list<int|string|array{0: int, 1: string, 2: int}>
     */
    private function normalizeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (\is_array($token)) {
                $out[] = [$token[0], $token[1], $token[2] ?? 1];
            } else {
                $out[] = $token;
            }
        }

        return $out;
    }
}
