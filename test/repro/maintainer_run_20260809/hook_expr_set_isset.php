<?php
class GetSetExpr {
    public string $name {
        get => (function () { echo "GET\n"; return 'x'; })();
        set => throw new Error('no');
    }
}
$o = new GetSetExpr;
echo 'isset=', isset($o->name) ? 'Y' : 'N', "\n";
echo 'empty=', empty($o->name) ? 'E' : 'NE', "\n";
$rp = new ReflectionProperty(GetSetExpr::class, 'name');
echo 'virtual=', $rp->isVirtual() ? 'Y' : 'N', "\n";
