<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: filter_var() and filter_input() for web-form validation paths (#1122).
 */

$email = $_GET['email'] ?? '';
echo filter_var($email, FILTER_VALIDATE_EMAIL);
echo filter_var('42', FILTER_VALIDATE_INT);
echo filter_var('x', FILTER_VALIDATE_INT) === false ? '0' : '1';
echo filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT) === null ? 'm' : 'x';
