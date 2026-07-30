<?php
/**
 * #25361 — similar_text Reflection $percent default null (ext/standard/string.stub.php).
 */
$p = (new ReflectionFunction('similar_text'))->getParameters()[2];
$t = $p->getType();
echo 'name=', $p->getName(), "\n";
echo 'type=', null !== $t ? (string) $t : 'none', "\n";
echo 'allowsNull=', (int) $p->allowsNull(), "\n";
echo 'def=', var_export($p->getDefaultValue(), true), "\n";
echo 'byref=', (int) $p->isPassedByReference(), "\n";
echo 'opt=', (int) $p->isOptional(), "\n";
echo 'sim=', similar_text('aaa', 'aab'), "\n";
