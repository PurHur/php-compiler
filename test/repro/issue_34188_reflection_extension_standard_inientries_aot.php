<?php
/** AOT: ReflectionExtension::getINIEntries('standard') (#34188). */
$e = new ReflectionExtension('standard');
$c = $e->getINIEntries();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
echo ' assert.active=', is_array($c) && array_key_exists('assert.active', $c) ? '1' : '0';
echo ' user_agent=', is_array($c) && array_key_exists('user_agent', $c) ? '1' : '0';
echo ' assert.callback=', is_array($c) && array_key_exists('assert.callback', $c) ? '1' : '0';
echo ' user_agent_null=', (is_array($c) && array_key_exists('user_agent', $c) && null === $c['user_agent']) ? '1' : '0';
echo "\n";
