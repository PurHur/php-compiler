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
            'get_defined_constants_calendar_group.phpt',
            'get_defined_constants_categorize.phpt',
            'get_defined_constants_module_buckets.phpt',
            'get_defined_constants_phantom_modules.phpt',
            'get_defined_constants_user_bucket.phpt',
            'get_defined_constants_ftp_mysqli_buckets.phpt',
            'get_defined_constants_dom_pecl_buckets.phpt',
            'get_defined_constants_snmp_bucket.phpt',
            'get_defined_vars_extract.phpt',
            'get_defined_vars_extract_jit.phpt',
            'get_defined_vars_omits_unassigned.phpt',
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

    /** @covers issue #4517 */
    public function testGetDefinedVarsIncludesExtractImports(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function probe(): void {
    extract(['a' => 1, 'b' => 2]);
    $vars = get_defined_vars();
    ksort($vars);
    echo json_encode($vars), "\n";
}
probe();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'gdv_extract.php'));
        $this->assertSame('{"a":1,"b":2}' . "\n", ob_get_clean());
    }
}
