<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/462
 */
final class ConstStringFolderTest extends TestCase
{
    public function testFoldForIncludeResolvesDirConcatFromCfg(): void
    {
        $entry = realpath(__DIR__.'/../../compliance/cases/language/include_dir_literal/entry.php');
        $this->assertNotFalse($entry);
        $runtime = new Runtime();
        $script = $runtime->parser->parse((string) file_get_contents($entry), $entry);
        $runtime->preprocessor->traverse($script);

        $found = false;
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof \PHPCfg\Op\Expr\Include_) {
                continue;
            }
            $literal = ConstStringFolder::foldForInclude(
                $script->main->cfg,
                $child->expr,
                $entry
            );
            $this->assertStringEndsWith('/helper.php', $literal);
            $found = true;
            break;
        }
        $this->assertTrue($found, 'expected include op in entry CFG');
    }

    public function testFoldForIncludeResolvesMiniWebAppIndexRequires(): void
    {
        $index = realpath(__DIR__.'/../../../examples/003-MiniWebApp/public/index.php');
        $this->assertNotFalse($index);
        $runtime = new Runtime();
        $script = $runtime->parser->parse((string) file_get_contents($index), $index);
        $runtime->preprocessor->traverse($script);

        $paths = [];
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof \PHPCfg\Op\Expr\Include_) {
                continue;
            }
            $literal = ConstStringFolder::foldForInclude(
                $script->main->cfg,
                $child->expr,
                $index
            );
            if (null !== $literal) {
                $paths[] = $literal;
            }
        }
        $this->assertTrue(
            (bool) preg_grep('#/config\.php$#', $paths),
            'expected config.php path in: '.implode(', ', $paths)
        );
        $this->assertTrue(
            (bool) preg_grep('#/Router\.php$#', $paths),
            'expected Router.php path in: '.implode(', ', $paths)
        );
    }
}
