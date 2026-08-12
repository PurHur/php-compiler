<?php
/** Repro for #21877 — gzopen/gzfile/readgzfile(null) → ValueError under PROFILE=8.4. */
$expected = 'Path cannot be empty';
foreach (['gzopen' => static fn () => gzopen(null, 'r'), 'gzfile' => static fn () => gzfile(null), 'readgzfile' => static fn () => readgzfile(null)] as $name => $fn) {
    try {
        $fn();
        fwrite(STDERR, "fail:$name:no_throw\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail:$name:msg:".$e->getMessage()."\n");
            exit(1);
        }
        echo "$name: ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "fail:$name:".get_class($e).':'.$e->getMessage()."\n");
        exit(1);
    }
}
