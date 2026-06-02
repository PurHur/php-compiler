--TEST--
stdlib header_register_callback() — callback adds header before body (issue #3759)
--FILE--
<?php
header_register_callback(function (): void {
    header('X-Registered: 1');
});
echo "body\n";
$found = false;
foreach (headers_list() as $line) {
    if (0 === strncasecmp($line, 'X-Registered:', 13)) {
        $found = true;
    }
}
echo $found ? "ok\n" : "missing\n";
--EXPECT--
body
ok
