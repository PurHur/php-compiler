<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type\String_::implement always-on NestedJIT of common stdlib string/file helpers
 * (#35613 / peer #35609 quotemeta/ctype/sodium).
 *
 * Call sites ensureLinked before lookup so hello-world and other thin scripts skip
 * NestedJIT on the full load path (#32122 .1 mint class).
 */
final class TypeStringImplementLazyStdlibRuntimeShrinkTest extends TestCase
{
    public function testStringImplementDropsEagerStdlibEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('#35613', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function implementStrlen', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            'StringHtmlspecialchars::implement',
            'StringHtmlspecialcharsDecode::implement',
            'StringPregQuote::implement',
            'StringAddslashes::implement',
            'StringStripslashes::implement',
            'StringUrlencode::implement',
            'StringUrldecode::implement',
            'StringNl2br::implement',
            'StringUcwords::implement',
            'StringRandomBytes::implement',
            'StringSerialize::implement',
            'StringUnserialize::implement',
            'StringHttpBuildQuery::implement',
            'StringParseStr::implement',
            'StringDeployPath::implement',
            'StringReadfile::implement',
            'StringFileGetContents::implement',
            'MimeContentTypeRuntime::implement',
            'MetaTagsRuntime::implement',
            'GetBrowserRuntime::implement',
            'StringFilePutContents::implement',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'Type\\String_::implement must not eagerly '.$forbidden.' (#35613)'
            );
        }

        $this->assertStringContainsString(
            'LOAD_TYPE_STANDALONE === $this->context->loadType',
            $body,
            'standalone still defers String_::implement early (#13571)'
        );
        $this->assertStringNotContainsString(
            'StringBitwiseNot::implement',
            $body,
            'bitwise-not deferred — Helper call-site emitUnary (#35301 / #35614)'
        );
        $this->assertStringNotContainsString(
            'IniSet::implement',
            $body,
            'ini ABIs deferred — JitIni ensureLinked (#35614)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/htmlspecialchars.php' => 'StringHtmlspecialchars::ensureLinked',
            'ext/standard/JitHtmlspecialcharsDecode.php' => 'StringHtmlspecialcharsDecode::ensureLinked',
            'ext/standard/preg_quote.php' => 'StringPregQuote::ensureLinked',
            'ext/standard/addslashes.php' => 'StringAddslashes::ensureLinked',
            'ext/standard/stripslashes.php' => 'StringStripslashes::ensureLinked',
            'ext/standard/urlencode.php' => 'StringUrlencode::ensureLinked',
            'ext/standard/urldecode.php' => 'StringUrldecode::ensureLinked',
            'ext/standard/nl2br.php' => 'StringNl2br::ensureLinked',
            'ext/standard/ucwords.php' => 'StringUcwords::ensureLinked',
            'ext/standard/JitRandomBytes.php' => 'StringRandomBytes::ensureLinked',
            'ext/standard/JitSerialize.php' => 'StringSerialize::ensureLinked',
            'ext/standard/JitUnserialize.php' => 'StringUnserialize::ensureLinked',
            'ext/standard/http_build_query.php' => 'StringHttpBuildQuery::ensureLinked',
            'ext/standard/parse_str.php' => 'StringParseStr::ensureLinked',
            'ext/standard/JitDeployPath.php' => 'StringDeployPath::ensureLinked',
            'ext/standard/readfile.php' => 'StringReadfile::ensureLinked',
            'ext/standard/JitFileGetContents.php' => 'StringFileGetContents::ensureLinked',
            'ext/standard/JitMimeContentType.php' => 'MimeContentTypeRuntime::ensureLinked',
            'ext/standard/JitGetMetaTags.php' => 'MetaTagsRuntime::ensureLinked',
            'ext/standard/JitGetBrowser.php' => 'GetBrowserRuntime::ensureLinked',
            'ext/standard/JitFilePutContents.php' => 'StringFilePutContents::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $file = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $file, $rel.' must link before use (#35613)');
        }
    }

    public function testNoNewRuntimeCForLazyStdlibAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'htmlspecialchars.c',
            'addslashes.c',
            'urlencode.c',
            'serialize.c',
            'file_get_contents.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35613 — PHP JIT bridges only'
            );
        }
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('RUNTIME_C_SOURCES = [', $linker);
        $this->assertStringNotContainsString('htmlspecialchars', $linker);
    }
}
