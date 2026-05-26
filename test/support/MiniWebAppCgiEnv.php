<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Shared CGI env contract for examples/003-MiniWebApp VM/AOT/shell smokes (issue #790).
 *
 * PHPUnit gates call the static scenario helpers; bash scripts can emit the same keys via:
 *
 *   ./script/miniwebapp-cgi-env.php --json queryRouteHome
 *   ./script/miniwebapp-cgi-env.php --export queryRouteHome   # eval "$(./script/miniwebapp-cgi-env.php --export queryRouteHome)"
 *
 * Bash equivalents (home route, AOT/shell execute):
 *
 *   QUERY_STRING='route=home' \
 *   REQUEST_METHOD='GET' \
 *   SCRIPT_NAME='/index.php' \
 *   REQUEST_URI='/index.php?route=home'
 *
 * @see test/unit/MiniWebAppVmCliTest.php
 * @see test/unit/MiniWebAppAotExecuteTest.php
 * @see test/fixtures/cgi-env/miniwebapp-home.env
 * @see https://github.com/PurHur/php-compiler/issues/790
 */
final class MiniWebAppCgiEnv
{
    public const APP_NAME = 'MiniWebApp';

    public const PROJECT_REL = 'examples/003-MiniWebApp';

    public const PUBLIC_REL = self::PROJECT_REL.'/public';

    /**
     * @return array<string, string>
     */
    public static function queryRouteHome(): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'route=home',
        ];
    }

    /**
     * Shell/AOT home route with front-controller URI fields (#773, #738).
     *
     * @return array<string, string>
     */
    public static function shellQueryRouteHome(): array
    {
        return array_merge(self::queryRouteHome(), [
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/index.php?route=home',
            'PATH_INFO' => '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function queryRouteHello(string $name = 'Dev'): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'route=hello&name='.$name,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function postQueryRouteContact(string $name = 'PostDev'): array
    {
        return [
            'REQUEST_METHOD' => 'POST',
            'QUERY_STRING' => 'route=contact',
            'REQUEST_BODY' => 'name='.$name,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function queryRouteApiStatus(): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'route=api/status',
        ];
    }

    /** HTTP path for serve / serve-aot curls (issues #478, #610). */
    public static function httpPathQueryRouteHome(): string
    {
        return '/index.php?route=home';
    }

    public static function httpPathQueryRouteHello(string $name = 'Dev'): string
    {
        return '/index.php?route=hello&name='.$name;
    }

    public static function httpPathPathInfoHello(string $name = 'Dev'): string
    {
        return '/index.php/hello?name='.$name;
    }

    public static function httpPathPostQueryRouteContact(): string
    {
        return '/index.php?route=contact';
    }

    public static function httpPathQueryRouteApiStatus(): string
    {
        return '/index.php?route=api/status';
    }

    public static function httpPathStaticCss(): string
    {
        return '/assets/style.css';
    }

    /**
     * @return array<string, string>
     */
    public static function pathInfoHello(string $name = 'Dev'): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/hello',
            'QUERY_STRING' => 'name='.$name,
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * Front-controller home route via PATH_INFO (rebuild-examples / issue #2257).
     *
     * @return array<string, string>
     */
    public static function pathInfoHome(): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/home',
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * AOT execute: PATH_INFO hello without SCRIPT_NAME in the scenario overlay.
     *
     * @return array<string, string>
     */
    public static function aotPathInfoHello(string $name = 'Dev'): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/hello',
            'QUERY_STRING' => 'name='.$name,
        ];
    }

    /**
     * AOT execute: PATH_INFO api/status without SCRIPT_NAME in the scenario overlay.
     *
     * @return array<string, string>
     */
    public static function aotPathInfoApiStatus(): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/api/status',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pathInfoContact(): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/contact',
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pathInfoApiStatus(): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/api/status',
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pathInfoEmptyHome(): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '',
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pathInfoAbsentHome(): array
    {
        return [
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_METHOD' => 'GET',
        ];
    }

    /**
     * Front-controller fields for AOT binary execute from public/.
     *
     * @return array<string, string>
     */
    public static function aotFrontController(string $repoRoot): array
    {
        $publicDir = $repoRoot.'/'.self::PUBLIC_REL;
        $projectDir = $repoRoot.'/'.self::PROJECT_REL;

        return [
            'SCRIPT_FILENAME' => $publicDir.'/index.php',
            'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => $publicDir,
            'PHPC_DEPLOY_ROOT' => $projectDir,
        ];
    }

    /**
     * @return list<string>
     */
    public static function scenarioNames(): array
    {
        return [
            'queryRouteHome',
            'shellQueryRouteHome',
            'queryRouteHello',
            'postQueryRouteContact',
            'queryRouteApiStatus',
            'pathInfoHello',
            'pathInfoHome',
            'aotPathInfoHello',
            'pathInfoContact',
            'pathInfoApiStatus',
            'pathInfoEmptyHome',
            'pathInfoAbsentHome',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scenario(string $name, string $repoRoot = ''): array
    {
        switch ($name) {
            case 'queryRouteHome':
                return self::queryRouteHome();
            case 'shellQueryRouteHome':
                return self::shellQueryRouteHome();
            case 'queryRouteHello':
                return self::queryRouteHello();
            case 'postQueryRouteContact':
                return self::postQueryRouteContact();
            case 'queryRouteApiStatus':
                return self::queryRouteApiStatus();
            case 'pathInfoHello':
                return self::pathInfoHello();
            case 'pathInfoHome':
                return self::pathInfoHome();
            case 'aotPathInfoHello':
                return self::aotPathInfoHello();
            case 'pathInfoContact':
                return self::pathInfoContact();
            case 'pathInfoApiStatus':
                return self::pathInfoApiStatus();
            case 'pathInfoEmptyHome':
                return self::pathInfoEmptyHome();
            case 'pathInfoAbsentHome':
                return self::pathInfoAbsentHome();
            case 'aotFrontController':
                if ('' === $repoRoot) {
                    throw new \InvalidArgumentException('aotFrontController requires repo root');
                }

                return self::aotFrontController($repoRoot);
            default:
                throw new \InvalidArgumentException('Unknown MiniWebApp CGI scenario: '.$name);
        }
    }
}
