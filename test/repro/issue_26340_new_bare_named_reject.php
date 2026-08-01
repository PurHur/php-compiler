<?php

declare(strict_types=1);

/**
 * #26340 / #26287 / #20598 — bare `new Name->…` (no ctor `()`) is a Parse error on Zend 8.4+ too.
 *
 * RFC new_without_parentheses only omits *outer* parentheses: `new Name()->m()` is legal under
 * PROFILE≥8.4; inventing ctor `()` for `new Name->m()` would diverge from php-src.
 *
 * Verified Zend 8.4.24 / 8.5-cli: bare form exit 255; `new Name()->…` prints values.
 *
 * Run:
 *   php test/repro/issue_26340_new_bare_named_reject.php
 */

$bare = <<<'PHP'
<?php
class Foo { public function bar() { return 7; } public int $p = 3; }
echo new Foo->bar(), "\n";
echo new Foo->p, "\n";
PHP;

$rfc = <<<'PHP'
<?php
class Foo { public function bar() { return 7; } public int $p = 3; }
echo new Foo()->bar(), "\n";
echo new Foo()->p, "\n";
PHP;

$vm = dirname(__DIR__, 2) . '/bin/vm.php';
$tmpBare = tempnam(sys_get_temp_dir(), 'issue_26340_bare_');
$tmpRfc = tempnam(sys_get_temp_dir(), 'issue_26340_rfc_');
if (false === $tmpBare || false === $tmpRfc) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
file_put_contents($tmpBare, $bare);
file_put_contents($tmpRfc, $rfc);

$run = static function (string $profile, string $file) use ($vm): array {
    $cmd = 'PHP_COMPILER_PROFILE=' . escapeshellarg($profile)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($vm)
        . ' ' . escapeshellarg($file) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
};

$cleanup = static function () use ($tmpBare, $tmpRfc): void {
    @unlink($tmpBare);
    @unlink($tmpRfc);
};

foreach (['8.4', '8.5'] as $profile) {
    [$code, $out] = $run($profile, $tmpBare);
    if (255 !== $code || !str_contains($out, 'unexpected token "->"')) {
        fwrite(STDERR, "fail: bare new Name-> must parse-error under PROFILE={$profile}\nexit={$code}\n{$out}\n");
        $cleanup();
        exit(1);
    }
    echo "ok reject bare PROFILE={$profile}\n";

    [$code, $out] = $run($profile, $tmpRfc);
    $trim = trim($out);
    if (0 !== $code || !str_ends_with($trim, "7\n3")) {
        fwrite(STDERR, "fail: new Name()->… must run under PROFILE={$profile}\nexit={$code}\n{$out}\n");
        $cleanup();
        exit(1);
    }
    echo "ok allow RFC PROFILE={$profile}\n";
}

// Default 8.2 reference profile: RFC form still parse-errors (Zend 8.2).
[$code, $out] = $run('8.2', $tmpRfc);
if (255 !== $code || !str_contains($out, 'unexpected token "->"')) {
    fwrite(STDERR, "fail: new Name()->… must parse-error under PROFILE=8.2\nexit={$code}\n{$out}\n");
    $cleanup();
    exit(1);
}
echo "ok reject RFC PROFILE=8.2\n";

$cleanup();
echo "issue_26340 ok\n";
