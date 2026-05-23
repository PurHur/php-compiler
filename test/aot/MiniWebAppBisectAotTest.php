<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * Ordered #764 MiniWebApp AOT PHPT ladder (issues/880, script/miniwebapp-aot-bisect.sh).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group miniwebapp-bisect
 */
final class MiniWebAppBisectAotTest extends AotTest
{
    /**
     * ROADMAP bisect order — smallest failing step first (#764, #879).
     *
     * @var list<string> basename without .phpt
     */
    private const BISECT_LADDER = [
        'isset_object_property_array',
        'require_return_config',
        'nested_include_two_tier',
        'deploy_path_layout_nested',
        'miniwebapp_render_home',
        'layout_script_base',
        'coalesce_then_inherited_local',
        'coalesce_then_htmlspecialchars',
        'coalesce_scriptbase_htmlspecialchars',
        'coalesce_then_nested_include',
        'layout_title_branch',
        'layout_partial_chain',
        'method_include_void_array_property',
    ];

    public static function providePHPTests(): \Generator
    {
        $casesDir = dirname(__DIR__).'/fixtures/aot/cases';
        foreach (self::BISECT_LADDER as $basename) {
            $path = $casesDir.'/'.$basename.'.phpt';
            if (!is_file($path)) {
                throw new \RuntimeException("miniwebapp-bisect: missing fixture {$path}");
            }
            yield $basename => self::parsePHPT($path, $basename.'.phpt');
        }
    }
}
