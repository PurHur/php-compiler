<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on file I/O / URL ensureLinked (#34423 / peer #34414).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyFileIoRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerFileIoEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34423', $type);
        foreach ([
            'StringReadfile::ensureLinked($this->context)',
            'StringFileGetContents::ensureLinked($this->context)',
            'StringFilePutContents::ensureLinked($this->context)',
            'MimeContentTypeRuntime::ensureLinked($this->context)',
            'MetaTagsRuntime::ensureLinked($this->context)',
            'StringErrorLog::ensureLinked($this->context)',
            'GetHeadersRuntime::ensureLinked($this->context)',
            'ParseUrlRuntime::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34423)'
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34423 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/readfile.php' => 'StringReadfile::ensureLinked',
            'ext/standard/JitFileGetContents.php' => 'StringFileGetContents::ensureLinked',
            'ext/standard/JitFilePutContents.php' => 'StringFilePutContents::ensureLinked',
            'ext/standard/JitMimeContentType.php' => 'MimeContentTypeRuntime::ensureLinked',
            'ext/standard/JitGetMetaTags.php' => 'MetaTagsRuntime::ensureLinked',
            'ext/standard/JitErrorLog.php' => 'StringErrorLog::ensureLinked',
            'ext/standard/JitGetHeaders.php' => 'GetHeadersRuntime::ensureLinked',
            'ext/standard/JitParseUrl.php' => 'ParseUrlRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34423)');
        }
    }

    public function testNoNewRuntimeCForLazyFileIoAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'readfile.c',
            'file_get_contents.c',
            'file_put_contents.c',
            'mime_content_type.c',
            'get_meta_tags.c',
            'error_log.c',
            'get_headers.c',
            'parse_url.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34423)');
        }
    }
}
