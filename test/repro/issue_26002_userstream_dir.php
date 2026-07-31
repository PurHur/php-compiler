<?php
// #26002 — opendir/readdir/scandir dispatch dir_* on stream_wrapper_register() protocols
error_reporting(E_ALL);

class Issue26002Wrap
{
    public $context;

    /** @var list<string> */
    private $entries = ['.', '..', 'a', 'b'];

    private $i = 0;

    public function dir_opendir($path, $options)
    {
        echo 'op:'.$path.':'.$options, "\n";
        $this->i = 0;

        return true;
    }

    public function dir_readdir()
    {
        if ($this->i >= count($this->entries)) {
            return false;
        }
        $entry = $this->entries[$this->i];
        $this->i = $this->i + 1;

        return $entry;
    }

    public function dir_rewinddir()
    {
        echo "rewind\n";
        $this->i = 0;

        return true;
    }

    public function dir_closedir()
    {
        echo "close\n";
    }
}

stream_wrapper_register('uwdir', Issue26002Wrap::class);

$d = opendir('uwdir://root');
echo 'opendir='.var_export($d !== false, true), "\n";
if ($d) {
    $out = [];
    while (($e = readdir($d)) !== false) {
        $out[] = $e;
    }
    rewinddir($d);
    $out2 = [];
    while (($e = readdir($d)) !== false) {
        $out2[] = $e;
    }
    closedir($d);
    echo 'readdir='.implode(',', $out), "\n";
    echo 'readdir2='.implode(',', $out2), "\n";
}
echo 'scandir='.var_export(scandir('uwdir://root'), true), "\n";
echo 'scandir_desc='.var_export(scandir('uwdir://root', SCANDIR_SORT_DESCENDING), true), "\n";
echo 'scandir_none='.var_export(scandir('uwdir://root', SCANDIR_SORT_NONE), true), "\n";
stream_wrapper_unregister('uwdir');
