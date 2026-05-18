<?php

declare(strict_types=1);

/**
 * Yay macro parser references tokenizer constants removed in PHP 8.0.
 * Define them in the Yay namespace so autoload can load parsers.php on PHP 8+.
 */
namespace Yay {
    if (!\defined('Yay\\T_CURLY_OPEN')) {
        \define('Yay\\T_CURLY_OPEN', 380);
    }
    if (!\defined('Yay\\T_DOLLAR_OPEN_CURLY_BRACES')) {
        \define('Yay\\T_DOLLAR_OPEN_CURLY_BRACES', 381);
    }
}
