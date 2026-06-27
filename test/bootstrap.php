<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: load PHP 8+ compat shims before Composer autoload (Yay / Pre).
 */
require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
// Avoid libffi dlopen in PHPUnit parent before JIT compliance children (#98, #2055).
putenv('PHP_COMPILER_SKIP_LLVM_PRELOAD=1');
$_ENV['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
$_SERVER['PHP_COMPILER_SKIP_LLVM_PRELOAD'] = '1';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/LlvmToolchain.php';
require __DIR__.'/support/MiniWebAppCgiEnv.php';
require __DIR__.'/support/CgiCookieJar.php';
require __DIR__.'/support/SessionsWebCgiEnv.php';
require __DIR__.'/support/PropertyHookTestSkip.php';

// PHPUnit xml may force relative LD_LIBRARY_PATH=./.llvm; normalize paths only (#98).
// Do not call isReady() here — PHPLLVM dlopen in the parent poisons bin/jit.php children.
\PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
