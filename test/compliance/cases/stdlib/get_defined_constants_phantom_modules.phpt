--TEST--
get_defined_constants(true) — no phantom module buckets when extension unloaded (#18048, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$phantom = [];
foreach (['sockets', 'xsl', 'inotify'] as $module) {
    if (!extension_loaded($module)) {
        $c = get_defined_constants(true);
        $count = isset($c[$module]) ? count($c[$module]) : 0;
        if ($count > 0) {
            $phantom[] = $module;
        }
    }
}
echo [] === $phantom ? "ok\n" : 'phantom='.implode(',', $phantom)."\n";
--EXPECT--
ok
