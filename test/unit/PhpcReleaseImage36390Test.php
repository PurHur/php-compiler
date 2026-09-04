<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Structural gates for the user-facing phpc release image / SDK (#36390).
 *
 * Does not build the image (that is make docker-build-phpc-release + cold-build-check --image).
 */
final class PhpcReleaseImage36390Test extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testDockerfileAndEntrypointExist(): void
    {
        $this->assertFileExists(self::$root.'/Docker/release/Dockerfile');
        $this->assertFileExists(self::$root.'/Docker/release/entrypoint.sh');
        $dockerfile = (string) file_get_contents(self::$root.'/Docker/release/Dockerfile');
        $this->assertStringContainsString('ghcr.io/purhur/phpc', $dockerfile);
        $this->assertStringContainsString('ENTRYPOINT', $dockerfile);
        $this->assertStringContainsString('composer install', $dockerfile);
        $this->assertStringContainsString('--no-dev', $dockerfile);
    }

    public function testBuildAndPackScriptsExistAndMentionBudget(): void
    {
        $build = self::$root.'/script/build-phpc-release-image.sh';
        $pack = self::$root.'/script/pack-phpc-sdk.sh';
        $this->assertFileExists($build);
        $this->assertFileExists($pack);
        $buildBody = (string) file_get_contents($build);
        $this->assertStringContainsString('PHPC_RELEASE_IMAGE', $buildBody);
        $this->assertStringContainsString('Docker/release/Dockerfile', $buildBody);
        $this->assertStringContainsString('unit.o is the cold-build', $buildBody);
        $this->assertStringNotContainsString('**/*.o', $buildBody);
        $packBody = (string) file_get_contents($pack);
        $this->assertStringContainsString('1073741824', $packBody);
        $this->assertStringContainsString('phpc-host', $packBody);
    }

    public function testColdBuildCheckSupportsImageMode(): void
    {
        $script = self::$root.'/script/cold-build-check.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('--image', $body);
        $this->assertStringContainsString('COLD_BUILD_MAX_SECONDS:=300', $body);
        $this->assertStringContainsString('mode":"image"', $body);
    }

    public function testMakefileTargetsAndGettingStartedLeadWithDocker(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('docker-build-phpc-release:', $makefile);
        $this->assertStringContainsString('pack-phpc-sdk:', $makefile);
        $this->assertStringContainsString('cold-build-check-image:', $makefile);

        $gs = (string) file_get_contents(self::$root.'/docs/GETTING-STARTED.md');
        $this->assertStringContainsString('Install (app authors — Docker only)', $gs);
        $this->assertLessThan(
            strpos($gs, 'Bootstrap contributors'),
            strpos($gs, 'ghcr.io/purhur/phpc'),
            'Docker install section must appear before contributor bootstrap'
        );
    }
}
