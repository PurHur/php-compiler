--TEST--
Stdlib: STDIN/STDOUT/STDERR stream constants defined for CLI (ext/standard/streams.c, #10163)
--FILE--
<?php
foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
    echo $name, '=', defined($name) ? 'yes' : 'no', "\n";
}
if (defined('STDOUT')) {
    fprintf(STDOUT, "fprintf_ok\n");
}
if (defined('STDERR')) {
    echo 'stderr_type=', get_resource_type(STDERR), "\n";
}
--EXPECT--
STDIN=yes
STDOUT=yes
STDERR=yes
fprintf_ok
stderr_type=stream
