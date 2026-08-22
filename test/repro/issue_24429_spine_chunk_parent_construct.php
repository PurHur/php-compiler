<?php
// #24429 — SPINE_CHUNK must not abort on parent::__construct() when the parent
// ctor is outside the chunk (ext/ds NestedJIT methods → VmClassMethod).
class OutsideChunkParent
{
}

class ChunkChild extends OutsideChunkParent
{
    public function __construct()
    {
        parent::__construct();
    }
}

new ChunkChild();
echo "ok\n";
