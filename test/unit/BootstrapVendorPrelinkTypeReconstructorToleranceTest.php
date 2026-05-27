<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPTypes\State;
use PHPTypes\TypeReconstructor;

final class BootstrapVendorPrelinkTypeReconstructorToleranceTest extends TestCase
{
    public function testVendorPrelinkModeSuppressesUnknownTypeDeclFromTypeReconstructor(): void
    {
        $prev = getenv('PHP_COMPILER_VENDOR_PRELINK');
        putenv('PHP_COMPILER_VENDOR_PRELINK=1');
        try {
            $rt = new Runtime(Runtime::MODE_NORMAL);
            $rt->typeReconstructor = new class extends TypeReconstructor {
                public function resolve(State $state): void
                {
                    throw new \RuntimeException('Unknown type declaration found: */');
                }
            };

            $script = $rt->parse('<?php function f(): void {}', 'test.php');
            $this->assertNotNull($script);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_VENDOR_PRELINK');
            } else {
                putenv('PHP_COMPILER_VENDOR_PRELINK='.$prev);
            }
        }
    }

    public function testNormalModeStillThrowsUnknownTypeDeclFromTypeReconstructor(): void
    {
        $prev = getenv('PHP_COMPILER_VENDOR_PRELINK');
        putenv('PHP_COMPILER_VENDOR_PRELINK');
        try {
            $rt = new Runtime(Runtime::MODE_NORMAL);
            $rt->typeReconstructor = new class extends TypeReconstructor {
                public function resolve(State $state): void
                {
                    throw new \RuntimeException('Unknown type declaration found: */');
                }
            };

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unknown type declaration found: */');
            $rt->parse('<?php function f(): void {}', 'test.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_VENDOR_PRELINK');
            } else {
                putenv('PHP_COMPILER_VENDOR_PRELINK='.$prev);
            }
        }
    }
}

