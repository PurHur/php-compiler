<?php
/**
 * #22564 — grapheme_str_contains is PHP 8.4+; PROFILE=8.2 must match Zend 8.2
 * (function_exists / is_callable false even when ICU-backed intl is loaded).
 */
echo 'fe=', function_exists('grapheme_str_contains') ? '1' : '0', "\n";
echo 'callable=', is_callable('grapheme_str_contains') ? '1' : '0', "\n";
echo 'strimwidth_fe=', function_exists('grapheme_strimwidth') ? '1' : '0', "\n";
