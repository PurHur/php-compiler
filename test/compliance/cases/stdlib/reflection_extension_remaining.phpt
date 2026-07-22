--TEST--
stdlib ReflectionExtension remaining methods after #18326 (#22247)
--FILE--
<?php
$re = new ReflectionExtension('standard');
foreach (['getDependencies', 'getINIEntries', 'getClassNames', 'isPersistent', 'isTemporary', 'info', '__toString'] as $m) {
    echo $m, '=', method_exists($re, $m) ? 'Y' : 'N', "\n";
}
$deps = $re->getDependencies();
echo 'deps_session=', $deps['session'] ?? 'missing', "\n";
$ini = $re->getINIEntries();
echo 'ini_user_agent_null=', (array_key_exists('user_agent', $ini) && null === $ini['user_agent']) ? 'Y' : 'N', "\n";
echo 'classnames_match_keys=', ($re->getClassNames() === array_keys($re->getClasses())) ? 'Y' : 'N', "\n";
echo 'persistent=', $re->isPersistent() ? 'Y' : 'N', "\n";
echo 'temporary=', $re->isTemporary() ? 'Y' : 'N', "\n";
ob_start();
$re->info();
$info = ob_get_clean();
echo 'info_has_standard=', (false !== stripos($info, 'standard')) ? 'Y' : 'N', "\n";
$s = (string) $re;
echo 'tostring_ok=', (false !== strpos($s, 'Extension [') && false !== strpos($s, '<persistent>') && false !== strpos($s, 'standard')) ? 'Y' : 'N', "\n";
?>
--EXPECT--
getDependencies=Y
getINIEntries=Y
getClassNames=Y
isPersistent=Y
isTemporary=Y
info=Y
__toString=Y
deps_session=Optional
ini_user_agent_null=Y
classnames_match_keys=Y
persistent=Y
temporary=N
info_has_standard=Y
tostring_ok=Y
