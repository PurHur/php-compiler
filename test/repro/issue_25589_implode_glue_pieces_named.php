<?php
// Repro #25589: Zend rejects named glue/pieces; separator/array stay valid.
$ok = true;
try {
    implode(glue: ',', pieces: [1, 2]);
    echo "FAIL: glue accepted\n";
    $ok = false;
} catch (Error $e) {
    echo $e->getMessage() === 'Unknown named parameter $glue' ? "glue:OK\n" : ("glue:".$e->getMessage()."\n");
}
try {
    join(pieces: [1, 2], separator: ',');
    echo "FAIL: pieces accepted\n";
    $ok = false;
} catch (Error $e) {
    echo $e->getMessage() === 'Unknown named parameter $pieces' ? "pieces:OK\n" : ("pieces:".$e->getMessage()."\n");
}
$r = implode(separator: ',', array: [1, 2]);
echo $r === '1,2' ? "separator:OK\n" : ("separator:".$r."\n");
exit($ok && $r === '1,2' ? 0 : 1);
