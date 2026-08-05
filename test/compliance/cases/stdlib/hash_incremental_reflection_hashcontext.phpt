--TEST--
stdlib hash_update_*/hash_final Reflection HashContext stubs (#27737, ext/hash/hash.stub.php)
--FILE--
<?php
foreach (['hash_update_stream', 'hash_update_file', 'hash_final'] as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : 'none')
            . ($p->isOptional() ? '?' : '');
    }
    echo $fn, '|', implode(',', $parts), PHP_EOL;
}
$td = sys_get_temp_dir() . '/hir' . getmypid();
file_put_contents($td, 'xy');
$h = fopen($td, 'r');
$ctx = hash_init('sha1');
hash_update_stream(context: $ctx, stream: $h, length: 1);
fclose($h);
hash_update_file(context: $ctx, filename: $td);
echo hash_final(context: $ctx, binary: false), PHP_EOL;
unlink($td);
?>
--EXPECT--
hash_update_stream|context:HashContext,stream:none,length:int?
hash_update_file|context:HashContext,filename:string,stream_context:none?
hash_final|context:HashContext,binary:bool?
f22e4533bea75c566975b0539c4ba4a42e08d5dc
