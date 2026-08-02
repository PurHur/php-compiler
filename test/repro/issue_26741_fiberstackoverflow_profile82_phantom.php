<?php
/**
 * Issue #26741 — FiberStackOverflow withheld on default / Zend 8.2 reference profile.
 *
 * Zend 8.2: class_exists false. VM default: false. VM PROFILE=8.4: true.
 *
 * Run:
 *   php test/repro/issue_26741_fiberstackoverflow_profile82_phantom.php
 *   php bin/vm.php test/repro/issue_26741_fiberstackoverflow_profile82_phantom.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26741_fiberstackoverflow_profile82_phantom.php
 */
echo 'class_exists=', class_exists('FiberStackOverflow') ? '1' : '0', "\n";
echo 'declared=', in_array('FiberStackOverflow', get_declared_classes(), true) ? '1' : '0', "\n";
