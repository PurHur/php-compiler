<?php
/**
 * Issue #22247 — ReflectionExtension remaining methods after #18326.
 *
 * Compare Zend vs VM: method_exists + shapes for getDependencies / getINIEntries /
 * getClassNames / isPersistent / isTemporary / info / __toString.
 */
$re = new ReflectionExtension('standard');
foreach (['getDependencies', 'getINIEntries', 'getClassNames', 'isPersistent', 'isTemporary', 'info', '__toString'] as $m) {
    echo $m, '=', method_exists($re, $m) ? 'Y' : 'N', PHP_EOL;
}

$deps = $re->getDependencies();
echo 'deps_session=', isset($deps['session']) ? $deps['session'] : 'missing', PHP_EOL;

$ini = $re->getINIEntries();
echo 'ini_is_array=', is_array($ini) ? 'Y' : 'N', PHP_EOL;
echo 'ini_has_user_agent=', array_key_exists('user_agent', $ini) ? 'Y' : 'N', PHP_EOL;
echo 'ini_user_agent_null=', (array_key_exists('user_agent', $ini) && null === $ini['user_agent']) ? 'Y' : 'N', PHP_EOL;

$names = $re->getClassNames();
$classes = $re->getClasses();
echo 'classnames_match_keys=', ($names === array_keys($classes)) ? 'Y' : 'N', PHP_EOL;
echo 'persistent=', $re->isPersistent() ? 'Y' : 'N', PHP_EOL;
echo 'temporary=', $re->isTemporary() ? 'Y' : 'N', PHP_EOL;

ob_start();
$re->info();
$info = ob_get_clean();
echo 'info_nonempty=', ('' !== $info) ? 'Y' : 'N', PHP_EOL;
echo 'info_has_standard=', (false !== stripos($info, 'standard')) ? 'Y' : 'N', PHP_EOL;

$s = (string) $re;
echo 'tostring_has_extension=', (false !== strpos($s, 'Extension [')) ? 'Y' : 'N', PHP_EOL;
echo 'tostring_has_persistent=', (false !== strpos($s, '<persistent>')) ? 'Y' : 'N', PHP_EOL;
echo 'tostring_has_standard=', (false !== strpos($s, 'standard')) ? 'Y' : 'N', PHP_EOL;
