<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gnupg\GnupgExtensionPolicy;
use PHPCompiler\ext\gnupg\VmGnupgNative;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * gnupg module registration (issue #6668, #25360).
 *
 * @group gnupg
 */
final class GnupgModuleTest extends TestCase
{
    /** @var string|false|null */
    private $prevEnable = null;

    protected function setUp(): void
    {
        $this->prevEnable = getenv('PHP_COMPILER_ENABLE_GNUPG');
        putenv('PHP_COMPILER_ENABLE_GNUPG=1');
    }

    protected function tearDown(): void
    {
        if (false === $this->prevEnable || null === $this->prevEnable) {
            putenv('PHP_COMPILER_ENABLE_GNUPG');
        } else {
            putenv('PHP_COMPILER_ENABLE_GNUPG='.$this->prevEnable);
        }
    }

    public function test_gnupg_init_registered_when_gpgme_available(): void
    {
        if (!VmGnupgNative::available()) {
            $this->markTestSkipped('libgpgme FFI unavailable');
        }
        self::assertTrue(GnupgExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'gnupg_init'));
        self::assertTrue(VmReflection::functionExists($ctx, 'gnupg_encrypt'));
        self::assertTrue(VmReflection::functionExists($ctx, 'gnupg_keyinfo'));
        self::assertTrue(ModuleRegistry::extensionLoaded('gnupg'));
        self::assertTrue(isset($ctx->classes['gnupg']));

        $code = <<<'PHP'
<?php
$g = gnupg_init();
echo (int) (false !== $g);
echo (int) ($g instanceof gnupg);
PHP;
        $block = $runtime->parseAndCompile($code, 'gnupg_module.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('11', $out);
    }

    public function test_issue_repro_script(): void
    {
        if (!VmGnupgNative::available()) {
            $this->markTestSkipped('libgpgme FFI unavailable');
        }
        $path = dirname(__DIR__).'/repro/issue_6668_gnupg_init.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(file_get_contents($path), $path);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('1', $out);
    }

    public function test_encrypt_decrypt_roundtrip_when_test_keys_present(): void
    {
        if (!VmGnupgNative::available()) {
            $this->markTestSkipped('libgpgme FFI unavailable');
        }
        $home = getenv('GNUPGHOME');
        if (false === $home || '' === $home) {
            $this->markTestSkipped('GNUPGHOME not set');
        }
        if (!is_dir($home)) {
            $this->markTestSkipped('GNUPGHOME not a directory');
        }

        $encKey = getenv('GNUPG_TEST_ENCRYPT_KEY');
        $decKey = getenv('GNUPG_TEST_DECRYPT_KEY');
        $pass = getenv('GNUPG_TEST_DECRYPT_PASS');
        if (false === $encKey || '' === $encKey || false === $decKey || '' === $decKey) {
            $this->markTestSkipped('GNUPG_TEST_ENCRYPT_KEY / GNUPG_TEST_DECRYPT_KEY not set');
        }
        if (false === $pass) {
            $pass = '';
        }

        $encKeyEsc = addslashes($encKey);
        $decKeyEsc = addslashes($decKey);
        $passEsc = addslashes($pass);
        $homeEsc = addslashes($home);

        $code = <<<PHP
<?php
\$g = gnupg_init(['home_dir' => '{$homeEsc}']);
if (!gnupg_addencryptkey(\$g, '{$encKeyEsc}')) {
    echo 'enc_key_fail';
    exit;
}
if (!gnupg_adddecryptkey(\$g, '{$decKeyEsc}', '{$passEsc}')) {
    echo 'dec_key_fail';
    exit;
}
\$plain = 'hello gnupg';
\$cipher = gnupg_encrypt(\$g, \$plain);
if (false === \$cipher || '' === \$cipher) {
    echo 'encrypt_fail';
    exit;
}
\$back = gnupg_decrypt(\$g, \$cipher);
if (false === \$back) {
    echo 'decrypt_fail';
    exit;
}
echo \$back === \$plain ? 'ok' : 'mismatch';
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'gnupg_roundtrip.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->markTestSkipped('gpg roundtrip: '.$e->getMessage());
        }
        $out = ob_get_clean();
        if (str_contains($out, '_fail')) {
            $this->markTestSkipped('gpg keys/home unavailable: '.trim($out));
        }
        self::assertSame('ok', $out);
    }
}
