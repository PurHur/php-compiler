<?php
/**
 * AOT: ReflectionExtension('standard')->getINIEntries — count/keys/nulls (#34188).
 * Zend: count=14 incl. assert.callback/from/user_agent NULL; AOT pre-fix: count=0.
 */
$e = new ReflectionExtension('standard');
$c = $e->getINIEntries();
ksort($c);
echo 'count=', count($c), "\n";
echo 'assert.active=', array_key_exists('assert.active', $c) ? '1' : '0', "\n";
echo 'assert.callback_null=', (array_key_exists('assert.callback', $c) && null === $c['assert.callback']) ? '1' : '0', "\n";
echo 'from_null=', (array_key_exists('from', $c) && null === $c['from']) ? '1' : '0', "\n";
echo 'user_agent_null=', (array_key_exists('user_agent', $c) && null === $c['user_agent']) ? '1' : '0', "\n";
echo 'default_socket_timeout=', array_key_exists('default_socket_timeout', $c) ? '1' : '0', "\n";
