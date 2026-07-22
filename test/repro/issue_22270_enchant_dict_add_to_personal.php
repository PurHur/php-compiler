<?php

declare(strict_types=1);

/**
 * Issue #22270 — enchant_dict_add_to_personal is a deprecated alias of enchant_dict_add.
 * php-src: ext/enchant/enchant.stub.php
 */
echo 'add=', function_exists('enchant_dict_add') ? 'Y' : 'N', PHP_EOL;
echo 'personal=', function_exists('enchant_dict_add_to_personal') ? 'Y' : 'N', PHP_EOL;
