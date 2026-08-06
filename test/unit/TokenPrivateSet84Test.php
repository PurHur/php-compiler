<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.4 tokenizer surface: T_*_SET + T_PROPERTY_C (#28130).
 *
 * @group vm_tokenizer_native
 */
final class TokenPrivateSet84Test extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = false === $raw ? null : $raw;
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->prevProfile;
        }
    }

    public function test_php84_token_ids_match_php_src(): void
    {
        $map = TokenConstantsData::nameToId();
        self::assertSame(327, $map['T_PRIVATE_SET']);
        self::assertSame(328, $map['T_PROTECTED_SET']);
        self::assertSame(329, $map['T_PUBLIC_SET']);
        self::assertSame(353, $map['T_PROPERTY_C']);
        self::assertSame(330, $map['T_READONLY']);
    }

    public function test_scanner_emits_private_set_single_token(): void
    {
        $tokens = LanguageScanner::tokenize('<?php class C { public private(set) string $n; }');
        $names = [];
        foreach ($tokens as $t) {
            if (\is_array($t)) {
                $names[] = TokenConstantsData::idToName()[$t[0]] ?? ('#'.$t[0]);
            }
        }
        self::assertContains('T_PRIVATE_SET', $names);
        self::assertNotContains('T_PRIVATE', $names);
        self::assertContains('T_PUBLIC', $names);
    }

    public function test_scanner_emits_property_c(): void
    {
        $tokens = LanguageScanner::tokenize('<?php __PROPERTY__;');
        self::assertTrue(\is_array($tokens[1]));
        self::assertSame(TokenConstantsData::nameToId()['T_PROPERTY_C'], $tokens[1][0]);
        self::assertSame('__PROPERTY__', $tokens[1][1]);
    }

    public function test_vm_token_get_all_and_defined_constants(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$code = '<?php class C { public private(set) string $n; }';
$names = [];
foreach (token_get_all($code) as $t) {
    if (is_array($t)) {
        $names[] = token_name($t[0]);
    }
}
echo in_array('T_PRIVATE_SET', $names, true) ? "has_private_set\n" : "missing\n";
echo in_array('T_PRIVATE', $names, true) ? "still_split\n" : "coalesced\n";
echo 'T_PRIVATE_SET=', defined('T_PRIVATE_SET') ? (string) T_PRIVATE_SET : 'UNDEF', "\n";
echo 'T_PROPERTY_C=', defined('T_PROPERTY_C') ? (string) T_PROPERTY_C : 'UNDEF', "\n";
$p = token_get_all('<?php __PROPERTY__;');
echo is_array($p[1]) ? token_name($p[1][0]) : '?', "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'token_private_set_84.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "has_private_set\ncoalesced\nT_PRIVATE_SET=327\nT_PROPERTY_C=353\nT_PROPERTY_C\n",
            ob_get_clean()
        );
    }
}
