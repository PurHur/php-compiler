<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

/**
 * Scaffold a minimal lint-clean web project (issue #312, #632).
 */
final class PhpcInit
{
    public const PROFILE_DEFAULT = 'default';

    public const PROFILE_MINIWEBAPP = 'miniwebapp';

    public const PROFILE_SESSIONSWEB = 'sessionsweb';

    public const PROFILE_APIJSON = 'apijson';

    public const PROFILE_FILEUPLOAD = 'fileupload';

    public const PROFILE_THROWSWEB = 'throwsweb';

    /** @var array<string, list<string>> */
    private const PROFILE_TEMPLATES = [
        self::PROFILE_DEFAULT => [
            'phpc.json',
            'public/index.php',
            'README.md',
        ],
        self::PROFILE_APIJSON => [
            'phpc.json',
            'example.php',
            'README.md',
        ],
        self::PROFILE_SESSIONSWEB => [
            'phpc.json',
            'example.php',
            'README.md',
        ],
        self::PROFILE_FILEUPLOAD => [
            'phpc.json',
            'example.php',
            'README.md',
        ],
        self::PROFILE_THROWSWEB => [
            'phpc.json',
            'example.php',
            'README.md',
        ],
        self::PROFILE_MINIWEBAPP => [
            'phpc.json',
            'config.php',
            'public/index.php',
            'src/Router.php',
            'templates/layout.php',
            'templates/home.php',
            'templates/hello.php',
            'templates/contact.php',
            'templates/thankyou.php',
            'assets/style.css',
            'README.md',
        ],
    ];

    public static function isKnownProfile(string $profile): bool
    {
        return isset(self::PROFILE_TEMPLATES[$profile]);
    }

    /**
     * @return list<string>
     */
    public static function knownProfiles(): array
    {
        return array_keys(self::PROFILE_TEMPLATES);
    }

    public static function run(string $repoRoot, string $targetDir, bool $force, string $profile = self::PROFILE_DEFAULT): int
    {
        if (!self::isKnownProfile($profile)) {
            fwrite(STDERR, "phpc init: unknown profile: {$profile}\n");
            fwrite(STDERR, '  known profiles: '.implode(', ', self::knownProfiles())."\n");

            return 1;
        }

        $target = $targetDir;
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            fwrite(STDERR, "phpc init: cannot create directory: {$targetDir}\n");

            return 1;
        }

        $resolved = realpath($target);
        if (false === $resolved) {
            fwrite(STDERR, "phpc init: invalid target directory: {$targetDir}\n");

            return 1;
        }

        $templateDir = self::PROFILE_DEFAULT === $profile ? 'init' : 'init-'.$profile;
        $templateBase = $repoRoot.'/templates/'.$templateDir;
        if (!is_dir($templateBase)) {
            fwrite(STDERR, "phpc init: templates missing at {$templateBase}\n");

            return 1;
        }

        foreach (self::PROFILE_TEMPLATES[$profile] as $relative) {
            $source = $templateBase.'/'.$relative;
            if (!is_file($source)) {
                fwrite(STDERR, "phpc init: template missing: {$relative}\n");

                return 1;
            }

            $dest = $resolved.'/'.$relative;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                fwrite(STDERR, "phpc init: cannot create directory: {$destDir}\n");

                return 1;
            }

            if (is_file($dest) && !$force) {
                fwrite(STDERR, "phpc init: already exists (use --force): {$relative}\n");

                return 1;
            }

            $contents = file_get_contents($source);
            if (false === $contents) {
                fwrite(STDERR, "phpc init: cannot read template: {$relative}\n");

                return 1;
            }

            if (false === file_put_contents($dest, $contents)) {
                fwrite(STDERR, "phpc init: cannot write: {$relative}\n");

                return 1;
            }
        }

        fwrite(STDOUT, "Initialized php-compiler project in {$resolved} (profile: {$profile})\n");
        if (self::PROFILE_MINIWEBAPP === $profile) {
            fwrite(STDOUT, "  phpc lint --all .\n");
            fwrite(STDOUT, "  phpc serve 127.0.0.1:8080 .\n");
            fwrite(STDOUT, "  curl -s 'http://127.0.0.1:8080/index.php/hello?name=Dev'\n");
        } elseif (self::PROFILE_APIJSON === $profile) {
            fwrite(STDOUT, "  phpc lint example.php\n");
            fwrite(STDOUT, "  phpc run example.php\n");
            fwrite(STDOUT, "  phpc serve 127.0.0.1:8080 .\n");
            fwrite(STDOUT, "  curl -s -D - 'http://127.0.0.1:8080/example.php'\n");
        } elseif (self::PROFILE_SESSIONSWEB === $profile) {
            fwrite(STDOUT, "  phpc lint example.php\n");
            fwrite(STDOUT, "  phpc serve 127.0.0.1:8080 .\n");
            fwrite(STDOUT, "  jar=/tmp/phpc-sessionsweb.jar\n");
            fwrite(STDOUT, "  curl -s -c \"\$jar\" 'http://127.0.0.1:8080/example.php'\n");
            fwrite(STDOUT, "  curl -s -b \"\$jar\" -c \"\$jar\" -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'\n");
            fwrite(STDOUT, "  curl -s -b \"\$jar\" 'http://127.0.0.1:8080/example.php'\n");
        } elseif (self::PROFILE_FILEUPLOAD === $profile) {
            fwrite(STDOUT, "  phpc lint example.php\n");
            fwrite(STDOUT, "  phpc serve 127.0.0.1:8080 .\n");
            fwrite(STDOUT, "  curl -s -F 'doc=@README.md' http://127.0.0.1:8080/example.php\n");
        } elseif (self::PROFILE_THROWSWEB === $profile) {
            fwrite(STDOUT, "  phpc lint example.php\n");
            fwrite(STDOUT, "  phpc run example.php\n");
            fwrite(STDOUT, "  phpc serve 127.0.0.1:8080 .\n");
            fwrite(STDOUT, "  curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid\n");
        } else {
            fwrite(STDOUT, "  phpc lint public/index.php\n");
            fwrite(STDOUT, "  phpc run public/index.php\n");
        }

        return 0;
    }
}
