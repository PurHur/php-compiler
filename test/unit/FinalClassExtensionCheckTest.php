<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3406 */
final class FinalClassExtensionCheckTest extends TestCase
{
    public function testExtendFinalClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
class C extends F {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend final class F');
        $runtime->parseAndCompile($code, 'extend_final.php');
    }

    public function testNonFinalExtensionCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {}
class Child extends Base {}
echo Child::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Child\n", ob_get_clean());
    }

    public function testFinalClassWithoutChildCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_only.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testIndirectExtensionThroughNonFinalParentFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
class Mid extends F {}
class Leaf extends Mid {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class Mid cannot extend final class F');
        $runtime->parseAndCompile($code, 'chain.php');
    }

    /** @covers issue #9722 */
    public function testExtendFinalClassAfterRuntimeStatementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class C {}
try {
    new C;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
class D extends C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class D cannot extend final class C');
        $runtime->parseAndCompile($code, 'final_after_runtime.php');
    }

    /** @covers issue #21669 */
    public function testExtendBuiltinAttributeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadAttr extends Attribute {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadAttr cannot extend final class Attribute');
        $runtime->parseAndCompile($code, 'extend_attribute.php');
    }

    /** @covers issue #21669 */
    public function testAttributeReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export((new ReflectionClass(Attribute::class))->isFinal());
echo "\n";
#[Attribute]
class MyAttr {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'attribute_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\nok\n", ob_get_clean());
    }

    /**
     * @covers issue #28402
     * php-src Zend/zend_attributes.stub.php — final class AllowDynamicProperties /
     * ReturnTypeWillChange / SensitiveParameter / Override / Deprecated.
     */
    public function testBuiltinAttributeClassesReflectionIsFinalUnderForward84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
foreach ([
    AllowDynamicProperties::class,
    ReturnTypeWillChange::class,
    SensitiveParameter::class,
    Override::class,
    Deprecated::class,
] as $c) {
    var_export((new ReflectionClass($c))->isFinal());
    echo " $c\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'builtin_attr_isfinal.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $out = (string) ob_get_clean();
            self::assertStringContainsString('true AllowDynamicProperties', $out);
            self::assertStringContainsString('true ReturnTypeWillChange', $out);
            self::assertStringContainsString('true SensitiveParameter', $out);
            self::assertStringContainsString('true Override', $out);
            self::assertStringContainsString('true Deprecated', $out);
            self::assertStringNotContainsString('false ', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28402 */
    public function testExtendAllowDynamicPropertiesFailsAtCompileTime(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class BadAttr extends AllowDynamicProperties {}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Class BadAttr cannot extend final class AllowDynamicProperties');
            $runtime->parseAndCompile($code, 'extend_allow_dynamic_properties.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28135 — php-src 8.4+ final class GMP */
    public function testExtendGmpFailsUnderForward84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class BadGmp extends GMP {}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Class BadGmp cannot extend final class GMP');
            $runtime->parseAndCompile($code, 'extend_gmp.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28135 */
    public function testGmpReflectionIsFinalUnderForward84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
var_export((new ReflectionClass(GMP::class))->isFinal());
echo "\n";
PHP;
            $block = $runtime->parseAndCompile($code, 'gmp_isfinal.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("true\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28389 — php-src Zend/zend_fibers.stub.php final class Fiber */
    public function testExtendFiberFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadFiber extends Fiber {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadFiber cannot extend final class Fiber');
        $runtime->parseAndCompile($code, 'extend_fiber.php');
    }

    /** @covers issue #28389 — php-src Zend/zend_fibers.stub.php final class FiberError */
    public function testExtendFiberErrorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadFiberError extends FiberError {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadFiberError cannot extend final class FiberError');
        $runtime->parseAndCompile($code, 'extend_fibererror.php');
    }

    /** @covers issue #28389 */
    public function testFiberAndFiberErrorReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export((new ReflectionClass(Fiber::class))->isFinal());
echo "\n";
var_export((new ReflectionClass(FiberError::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'fiber_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\n", ob_get_clean());
    }

    /** @covers issue #28387 — php-src ext/random/random.stub.php final Randomizer + engines */
    public function testExtendRandomizerFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadRandomizer extends Random\Randomizer {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadRandomizer cannot extend final class Random\\Randomizer');
        $runtime->parseAndCompile($code, 'extend_randomizer.php');
    }

    /** @covers issue #28387 */
    public function testExtendRandomEngineMt19937FailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadMt extends Random\Engine\Mt19937 {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadMt cannot extend final class Random\\Engine\\Mt19937');
        $runtime->parseAndCompile($code, 'extend_mt19937.php');
    }

    /** @covers issue #28387 */
    public function testRandomClassesReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$classes = [
    Random\Randomizer::class,
    Random\Engine\Mt19937::class,
    Random\Engine\Secure::class,
    Random\Engine\PcgOneseq128XslRr64::class,
    Random\Engine\Xoshiro256StarStar::class,
];
foreach ($classes as $c) {
    var_export((new ReflectionClass($c))->isFinal());
    echo "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'random_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\ntrue\ntrue\ntrue\n", ob_get_clean());
    }

    /** @covers issue #28385 — php-src ext/zlib/zlib.stub.php final class InflateContext */
    public function testExtendInflateContextFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadInflateContext extends InflateContext {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadInflateContext cannot extend final class InflateContext');
        $runtime->parseAndCompile($code, 'extend_inflatecontext.php');
    }

    /** @covers issue #28385 — php-src ext/zlib/zlib.stub.php final class DeflateContext */
    public function testExtendDeflateContextFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadDeflateContext extends DeflateContext {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadDeflateContext cannot extend final class DeflateContext');
        $runtime->parseAndCompile($code, 'extend_deflatecontext.php');
    }

    /** @covers issue #28385 */
    public function testZlibContextReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
inflate_init(ZLIB_ENCODING_RAW);
deflate_init(ZLIB_ENCODING_RAW);
var_export((new ReflectionClass(InflateContext::class))->isFinal());
echo "\n";
var_export((new ReflectionClass(DeflateContext::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'zlib_context_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\n", ob_get_clean());
    }

    /** @covers issue #28391 — php-src ext/sockets/sockets.stub.php final class Socket */
    public function testExtendSocketFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadSocket extends Socket {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadSocket cannot extend final class Socket');
        $runtime->parseAndCompile($code, 'extend_socket.php');
    }

    /** @covers issue #28391 — php-src ext/sockets/sockets.stub.php final class AddressInfo */
    public function testExtendAddressInfoFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadAddressInfo extends AddressInfo {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadAddressInfo cannot extend final class AddressInfo');
        $runtime->parseAndCompile($code, 'extend_addressinfo.php');
    }

    /** @covers issue #28391 */
    public function testSocketAndAddressInfoReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export((new ReflectionClass(Socket::class))->isFinal());
echo "\n";
var_export((new ReflectionClass(AddressInfo::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'socket_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\n", ob_get_clean());
    }

    /** @covers issue #28386 — php-src ext/xml/xml.stub.php final class XMLParser */
    public function testExtendXmlParserFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadXmlParser extends XMLParser {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadXmlParser cannot extend final class XMLParser');
        $runtime->parseAndCompile($code, 'extend_xmlparser.php');
    }

    /** @covers issue #28386 */
    public function testXmlParserReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
xml_parser_create();
var_export((new ReflectionClass(XMLParser::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlparser_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\n", ob_get_clean());
    }

    /** @covers issue #28390 — php-src Zend/zend_weakrefs.stub.php final class WeakReference */
    public function testExtendWeakReferenceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadWeakReference extends WeakReference {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadWeakReference cannot extend final class WeakReference');
        $runtime->parseAndCompile($code, 'extend_weakreference.php');
    }

    /** @covers issue #28390 — php-src Zend/zend_weakrefs.stub.php final class WeakMap */
    public function testExtendWeakMapFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadWeakMap extends WeakMap {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadWeakMap cannot extend final class WeakMap');
        $runtime->parseAndCompile($code, 'extend_weakmap.php');
    }

    /** @covers issue #28390 */
    public function testWeakReferenceAndWeakMapReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export((new ReflectionClass(WeakReference::class))->isFinal());
echo "\n";
var_export((new ReflectionClass(WeakMap::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'weakref_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\n", ob_get_clean());
    }

    /** @covers issue #28384 — php-src ext/hash/hash.stub.php final class HashContext */
    public function testExtendHashContextFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class BadHashContext extends HashContext {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadHashContext cannot extend final class HashContext');
        $runtime->parseAndCompile($code, 'extend_hashcontext.php');
    }

    /** @covers issue #28384 */
    public function testHashContextReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
hash_init('sha256');
var_export((new ReflectionClass(HashContext::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'hashcontext_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\n", ob_get_clean());
    }

    /** @covers issue #28370 — php-src ext/openssl/openssl.stub.php final OpenSSL object classes */
    public function testExtendOpenSslObjectClassesFailAtCompileTime(): void
    {
        $runtime = new Runtime();
        foreach ([
            'OpenSSLCertificate' => 'BadOpenSSLCertificate',
            'OpenSSLAsymmetricKey' => 'BadOpenSSLAsymmetricKey',
            'OpenSSLCertificateSigningRequest' => 'BadOpenSSLCertificateSigningRequest',
        ] as $parent => $child) {
            $code = "<?php\nclass {$child} extends {$parent} {}\n";
            try {
                $runtime->parseAndCompile($code, "extend_{$parent}.php");
                $this->fail("expected CompileError extending {$parent}");
            } catch (\CompileError $e) {
                $this->assertSame(
                    "Class {$child} cannot extend final class {$parent}",
                    $e->getMessage()
                );
            }
        }
    }

    /** @covers issue #28370 */
    public function testOpenSslObjectClassesReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $c) {
    var_export((new ReflectionClass($c))->isFinal());
    echo "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'openssl_objects_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\ntrue\ntrue\n", ob_get_clean());
    }

    /** @covers issue #26531 */
    public function testExtendUnitEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
class C extends E {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend final class E');
        $runtime->parseAndCompile($code, 'extend_unit_enum.php');
    }

    /** @covers issue #26531 */
    public function testExtendBackedEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
class C extends E {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend final class E');
        $runtime->parseAndCompile($code, 'extend_backed_enum.php');
    }

    /** @covers issue #26531 */
    public function testEnumReflectionIsFinal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
var_export((new ReflectionClass(E::class))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_isfinal.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("true\n", ob_get_clean());
    }

    /** @covers issue #26531 */
    public function testImplementsEnumStillRejected(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
class C implements E {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessageMatches('/cannot implement E - it is not an interface/');
        $runtime->parseAndCompile($code, 'implements_enum.php');
    }
}
