<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_class() arity / Reflection under PROFILE=8.4 (#28310). */
final class GetClassBuiltinTest extends TestCase
{
    public function testVmGetClassReflectionArityUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
$r = new ReflectionFunction('get_class');
echo $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}
try {
    get_class(allow_string: true);
    echo "named-ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    get_class(new stdClass(), true);
    echo "positional-ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'get_class_refl.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                "1\nobject\n"
                ."Error:Unknown named parameter \$allow_string\n"
                ."ArgumentCountError:get_class() expects at most 1 argument, 2 given\n",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
