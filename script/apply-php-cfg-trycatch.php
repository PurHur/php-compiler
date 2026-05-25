#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Apply php-cfg try/catch parser lowering when unified patch fails (issue #57, #2084).
 */
$root = dirname(__DIR__);
$parserFile = $root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
$opFile = $root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php';
$stub = $root.'/patches/php-cfg-TryCatch.php.stub';
$bodyFile = $root.'/patches/php-cfg-trycatch-parser-body.php.inc';

if (!is_file($parserFile)) {
    fwrite(STDERR, "apply-php-cfg-trycatch: Parser.php missing — run composer install\n");
    exit(1);
}

if (!is_file($stub) || !is_file($bodyFile)) {
    fwrite(STDERR, "apply-php-cfg-trycatch: patch stubs missing\n");
    exit(1);
}

if (!is_dir(dirname($opFile))) {
    mkdir(dirname($opFile), 0755, true);
}
if (!is_file($opFile)) {
    copy($stub, $opFile);
}

$parser = (string) file_get_contents($parserFile);
if (str_contains($parser, 'new Op\\Stmt\\TryCatch') && str_contains($parser, '$afterCatch = null !== $finallyBlock')) {
    exit(0);
}

$body = rtrim((string) file_get_contents($bodyFile))."\n";
$replacement = $body;

if (str_contains($parser, 'new Op\\Stmt\\TryCatch')) {
    $updated = preg_replace(
        '/    protected function parseStmt_TryCatch\\(Stmt\\\\TryCatch \\$node\\)\\n    \\{.*?\\n    \\}\\n/ms',
        $replacement,
        $parser,
        1,
        $count
    );
    if (1 !== $count || !is_string($updated)) {
        fwrite(STDERR, "apply-php-cfg-trycatch: could not replace existing parseStmt_TryCatch\n");
        exit(1);
    }
    $parser = $updated;
} else {
    $needle = "    protected function parseStmt_TryCatch(Stmt\\TryCatch \$node)\n    {\n        // TODO: implement this!!!\n    }";
    if (!str_contains($parser, $needle)) {
        fwrite(STDERR, "apply-php-cfg-trycatch: parseStmt_TryCatch TODO stub not found\n");
        exit(1);
    }
    $parser = str_replace($needle, $replacement, $parser);
}

file_put_contents($parserFile, $parser);
fwrite(STDOUT, "apply-php-cfg-trycatch: Parser.php and TryCatch.php installed\n");
