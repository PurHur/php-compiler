--TEST--
stdlib hash_hkdf return + stream_context_set_option Reflection (#25845)
--FILE--
<?php
foreach (['hash_hkdf', 'stream_context_set_option'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $extra = '';
        if ($p->isDefaultValueAvailable()) {
            try {
                $extra = '='.var_export($p->getDefaultValue(), true);
            } catch (Throwable $e) {
                $extra = '=?';
            }
        } elseif ($p->isOptional()) {
            $extra = '=?';
        }
        $ps[] = ($p->isPassedByReference() ? '&' : '').$p->getName().':'.$t.$extra;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '-';
    echo $f.' => '.$ret.' :: '.implode(', ', $ps)."\n";
}
?>
--EXPECT--
hash_hkdf => string :: algo:string, key:string, length:int=0, info:string='', salt:string=''
stream_context_set_option => bool :: context:-, wrapper_or_options:array|string, option_name:?string=NULL, value:mixed=?
