<?php
/**
 * #25502 — Deprecated/Override withheld on 8.2 reference; present under PROFILE=8.4.
 * Run: php bin/vm.php test/repro/issue_25502_deprecated_override_profile.php
 *      PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25502_deprecated_override_profile.php
 */
var_export(class_exists('Deprecated', false));
echo "\n";
var_export(class_exists('Override', false));
echo "\n";
#[\Deprecated]
function h25502_repro() {}
var_export((new ReflectionFunction('h25502_repro'))->isDeprecated());
echo "\n";
