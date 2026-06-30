<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM/JIT/AOT compliance for get_defined_constants() / get_defined_vars() (issue #3135). */
final class GetDefinedConstantsVarsBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'get_defined_constants_vars.phpt',
            'get_defined_constants_vars_jit.phpt',
        ] as $file) {
            $path = __DIR__.'/../compliance/cases/stdlib/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }

    /** @covers issue #10934 */
    public function testGetDefinedVarsIncludesFileScopeAutoGlobals(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\Web\Superglobals::populateFromEnvironment($runtime->vmContext);
        \PHPCompiler\Web\Superglobals::populateCliArgv($runtime->vmContext, ['script.php']);
        $code = <<<'PHP'
<?php
$a = 1;
$keys = array_keys(get_defined_vars());
sort($keys);
echo implode(',', $keys), "\n";
echo array_key_exists('_GET', get_defined_vars()) ? '1' : '0', "\n";
function inner(): void {
    $b = 2;
    echo count(get_defined_vars()) === 1 ? 'inner_ok' : 'inner_bad';
}
inner();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'gdv_superglobals.php'));
        $this->assertSame(
            "_COOKIE,_FILES,_GET,_POST,_SERVER,a,argc,argv\n1\ninner_ok",
            ob_get_clean()
        );
    }
}
