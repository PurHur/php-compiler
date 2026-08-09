<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\eio\EioConstants;
use PHPUnit\Framework\TestCase;

/** eio filesystem request depth (#27837). */
final class EioFsDepthTest extends TestCase
{
    /** @var string|false|null */
    private $prevEnable = null;

    /** @var string|false|null */
    private $prevProfile = null;

    protected function setUp(): void
    {
        $this->prevEnable = getenv('PHP_COMPILER_ENABLE_EIO');
        $this->prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_ENABLE_EIO=1');
        $_ENV['PHP_COMPILER_ENABLE_EIO'] = '1';
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        if (false === $this->prevEnable || null === $this->prevEnable) {
            putenv('PHP_COMPILER_ENABLE_EIO');
            unset($_ENV['PHP_COMPILER_ENABLE_EIO']);
        } else {
            putenv('PHP_COMPILER_ENABLE_EIO='.$this->prevEnable);
            $_ENV['PHP_COMPILER_ENABLE_EIO'] = $this->prevEnable;
        }
        if (false === $this->prevProfile || null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->prevProfile;
        }
    }

    public function testConstants(): void
    {
        self::assertSame(6, EioConstants::EIO_READ);
        self::assertSame(0x01, EioConstants::EIO_READDIR_DENTS);
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        self::assertTrue($ctx->isUserConstantDefined('EIO_READ'));
        self::assertTrue($ctx->isUserConstantDefined('EIO_READDIR_DENTS'));
        self::assertSame(6, $ctx->constants['EIO_READ']->toInt());
    }

    public function testStatMkdirUnlinkReaddirChmodViaPoll(): void
    {
        $runtime = new Runtime();
        $base = sys_get_temp_dir().'/php-compiler-eio-27837-'.getmypid();
        @mkdir($base);
        $dir = $base.'/subdir';
        $file = $base.'/f.txt';
        file_put_contents($file, 'x');
        $baseLit = var_export($base, true);

        $code = <<<PHP
<?php
\$base = {$baseLit};
\$dir = \$base.'/subdir';
\$file = \$base.'/f.txt';
\$ok = [];
eio_mkdir(\$dir, 0755, EIO_PRI_DEFAULT, function (\$d, \$r) use (&\$ok) {
    \$ok['mkdir'] = (int) \$r;
}, null);
eio_stat(\$file, EIO_PRI_DEFAULT, function (\$d, \$r) use (&\$ok) {
    \$ok['stat'] = is_array(\$r) && isset(\$r['size']) ? (int) \$r['size'] : -1;
}, null);
eio_chmod(\$file, 0644, EIO_PRI_DEFAULT, function (\$d, \$r) use (&\$ok) {
    \$ok['chmod'] = (int) \$r;
}, null);
eio_readdir(\$base, EIO_READDIR_DENTS, EIO_PRI_DEFAULT, function (\$d, \$r) use (&\$ok) {
    \$ok['readdir'] = is_array(\$r) && isset(\$r['names']) && is_array(\$r['names']) ? count(\$r['names']) : -1;
}, null);
eio_unlink(\$file, EIO_PRI_DEFAULT, function (\$d, \$r) use (&\$ok) {
    \$ok['unlink'] = (int) \$r;
}, null);
while (eio_nreqs()) {
    eio_poll();
}
echo isset(\$ok['mkdir']) ? \$ok['mkdir'] : 'm';
echo isset(\$ok['stat']) ? \$ok['stat'] : 's';
echo isset(\$ok['chmod']) ? \$ok['chmod'] : 'c';
echo isset(\$ok['readdir']) && \$ok['readdir'] >= 1 ? 'R' : 'r';
echo isset(\$ok['unlink']) ? \$ok['unlink'] : 'u';
echo defined('EIO_READ') ? 'Y' : 'N';
PHP;
        $block = $runtime->parseAndCompile($code, 'eio_fs_depth.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('010R0Y', $out);
        self::assertFalse(is_file($file));
        self::assertTrue(is_dir($dir));
        @rmdir($dir);
        @rmdir($base);
    }
}
