<?php
var_export(utf8_encode(65));
echo "\n";
var_export(utf8_decode(195));
echo "\n";

try {
    utf8_encode([]);
    echo "no throw encode\n";
} catch (TypeError $e) {
    echo "encode TypeError\n";
} catch (Throwable $e) {
    echo "encode ", get_class($e), "\n";
}

try {
    utf8_decode(new stdClass());
    echo "no throw decode\n";
} catch (TypeError $e) {
    echo "decode TypeError\n";
} catch (Throwable $e) {
    echo "decode ", get_class($e), "\n";
}
