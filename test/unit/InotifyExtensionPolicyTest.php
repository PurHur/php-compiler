<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\inotify\InotifyExtensionPolicy;
use PHPCompiler\ext\standard\VmInfo;
use PHPUnit\Framework\TestCase;

/** ext/inotify advertisement — withheld on reference profile (#18049). */
final class InotifyExtensionPolicyTest extends TestCase
{
    public function test_inotify_withheld_on_reference_profile(): void
    {
        self::assertFalse(InotifyExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(VmInfo::extension_loaded('inotify'));
        self::assertFalse($runtime->vmContext->constantFetch('IN_ACCESS') instanceof \PHPCompiler\VM\Variable);

        $code = <<<'PHP'
<?php
echo (int) extension_loaded('inotify');
echo (int) function_exists('inotify_init');
echo (int) defined('IN_ACCESS');
$c = get_defined_constants(true);
echo isset($c['inotify']) ? count($c['inotify']) : 0;
PHP;
        $block = $runtime->parseAndCompile($code, 'inotify_policy.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000', ob_get_clean());
    }
}
