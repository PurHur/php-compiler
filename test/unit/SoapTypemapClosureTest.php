<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient typemap from_xml Closure callback (#31845).
 *
 * @covers issue #31845
 */
final class SoapTypemapClosureTest extends TestCase
{
    public function testTypemapFromXmlClosure(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $resp = $root.'/test/fixtures/soap/book.response.xml';
        $code = sprintf(
            <<<'PHP'
<?php
$resp = %s;
$from = static function (string $xml): string {
    if (preg_match('/<title[^>]*>([^<]*)</', $xml, $m)) {
        return 'MAPPED:'.$m[1];
    }
    return 'MAPPED';
};
$client = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'typemap' => [[
        'type_ns' => 'urn:book',
        'type_name' => 'Book',
        'from_xml' => $from,
    ]],
]);
$r = $client->__soapCall('getBook', []);
echo (is_string($r) && $r === 'MAPPED:Dune') ? "ok\n" : 'fail:'.var_export($r, true)."\n";
PHP,
            var_export($resp, true)
        );

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'soap_typemap_closure_unit.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ok\n", $out);
    }
}
