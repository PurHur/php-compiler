<?php
/**
 * #22787 — IMAGETYPE_HEIF is PHP 8.5+ only (php-src ext/standard/image.c).
 * PROFILE=8.2: defined() false + COUNT 20; PROFILE=8.5: defined() true + COUNT 21.
 * Value of HEIF is covered by compliance imagetype_heif_profile85.phpt (bare ConstFetch
 * inside a dead if-branch still fails AOT lowering when the constant is unregistered).
 */
echo 'HEIF_defined=' . (defined('IMAGETYPE_HEIF') ? 'yes' : 'no') . "\n";
echo 'AVIF_defined=' . (defined('IMAGETYPE_AVIF') ? 'yes' : 'no') . "\n";
echo 'COUNT=' . constant('IMAGETYPE_COUNT') . "\n";
