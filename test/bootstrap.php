<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: load PHP 8+ compat shims before Composer autoload (Yay / Pre).
 */
require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/LlvmToolchain.php';
require __DIR__.'/support/MiniWebAppCgiEnv.php';

// PHPUnit xml may force relative LD_LIBRARY_PATH=./.llvm; normalize before JITTest (#98).
\PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
\PHPCompiler\LlvmToolchain::isReady(dirname(__DIR__));
