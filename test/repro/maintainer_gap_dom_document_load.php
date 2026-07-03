<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/dom_document_load_'.getmypid().'.xml';
file_put_contents($path, '<root><child/></root>');

$d = new DOMDocument();
if (true !== $d->load($path)) {
    echo "fail: load returned false\n";
    @unlink($path);
    exit(1);
}
if ('child' !== $d->documentElement->firstChild->nodeName) {
    echo 'fail: child name ', $d->documentElement->firstChild->nodeName, "\n";
    @unlink($path);
    exit(1);
}

$d2 = new DOMDocument();
if (false !== $d2->load($path.'.missing')) {
    echo "fail: missing file should return false\n";
    @unlink($path);
    exit(1);
}

@unlink($path);
echo "ok\n";
