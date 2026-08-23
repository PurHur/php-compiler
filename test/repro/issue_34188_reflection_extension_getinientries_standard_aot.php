<?php
/**
 * AOT: ReflectionExtension::getINIEntries('standard') — leftover of #34165 (#34188).
 * Zend/VM: count=14 with assert.active + null user_agent; AOT pre-fix: count=0.
 */
$e = new ReflectionExtension('standard');
$c = $e->getINIEntries();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
echo ' assert.active=', is_array($c) && array_key_exists('assert.active', $c) ? '1' : '0';
echo ' user_agent_null=', is_array($c) && array_key_exists('user_agent', $c) && $c['user_agent'] === null ? '1' : '0';
echo ' default_socket_timeout=', is_array($c) && array_key_exists('default_socket_timeout', $c) ? '1' : '0';
echo "\n";
