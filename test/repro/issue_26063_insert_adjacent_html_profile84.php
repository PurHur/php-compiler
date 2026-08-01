<?php
/**
 * #26063 — insertAdjacentHTML withheld on PROFILE=8.4 (php-src 8.4 stubs; 8.5+ only).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26063_insert_adjacent_html_profile84.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26063_insert_adjacent_html_profile84.php
 */
echo 'dom=', var_export(method_exists(Dom\Element::class, 'insertAdjacentHTML'), true), "\n";
echo 'legacy=', var_export(method_exists(DOMElement::class, 'insertAdjacentHTML'), true), "\n";
echo 'element=', var_export(method_exists(Dom\Element::class, 'insertAdjacentElement'), true), "\n";
echo 'text=', var_export(method_exists(Dom\Element::class, 'insertAdjacentText'), true), "\n";
