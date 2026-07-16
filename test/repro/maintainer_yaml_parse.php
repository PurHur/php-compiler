<?php
/**
 * Repro for #6275 — yaml_parse / yaml_emit (PECL yaml subset).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_yaml_parse.php
 */
$parsed = yaml_parse("foo: bar\n");
if (!\is_array($parsed) || ($parsed['foo'] ?? null) !== 'bar') {
    fwrite(STDERR, 'yaml_parse failed: '.var_export($parsed, true)."\n");
    exit(1);
}

$emitted = yaml_emit(['a' => 1]);
$round = yaml_parse($emitted);
if (!\is_array($round) || ($round['a'] ?? null) !== 1) {
    fwrite(STDERR, 'yaml_emit/parse roundtrip failed: '.var_export($emitted, true)."\n");
    exit(1);
}

$bad = @yaml_parse(":\n");
if (false !== $bad) {
    fwrite(STDERR, "invalid YAML should return false\n");
    exit(1);
}

echo "yaml_parse: ok\n";
