<?php

declare(strict_types=1);

/**
 * PHPCfg front-end smoke: Runtime::parse on fixture.php (issue #2409).
 * Invoked from PHPUnit under Zend; native bundle defers parse run (vendor PHPCfg at cold boot).
 */

function parser_unit_probe_fixture_path(): string
{
    return __DIR__.'/fixture.php';
}

function parser_unit_probe_fixture_source(): string
{
    return (string) file_get_contents(parser_unit_probe_fixture_path());
}

function parser_unit_probe_parse_smoke(): string
{
    if (!class_exists(\PHPCompiler\Runtime::class)) {
        return 'parser_unit_probe parse SKIP (no Runtime)';
    }

    $runtime = new \PHPCompiler\Runtime();
    $script = $runtime->parse(parser_unit_probe_fixture_source(), parser_unit_probe_fixture_path());
    if (!$script instanceof \PHPCfg\Script) {
        return 'parser_unit_probe parse FAIL';
    }

    return 'parser_unit_probe parse OK';
}
