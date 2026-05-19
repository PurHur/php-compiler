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
