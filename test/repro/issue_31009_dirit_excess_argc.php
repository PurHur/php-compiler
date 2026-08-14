<?php
/**
 * DirectoryIterator / FilesystemIterator residual excess argc (#31009).
 * php-src: ext/spl/spl_directory.c
 */
$dir = sys_get_temp_dir();
$it = new DirectoryIterator($dir);
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
show('rewind', static fn () => $it->rewind(1));
show('next', static fn () => $it->next(1));
show('key', static fn () => $it->key(1));
show('current', static fn () => $it->current(1));
show('valid', static fn () => $it->valid(1));
show('seek', static fn () => $it->seek(0, 'x'));
$fi = new FilesystemIterator($dir);
show('setFlags', static fn () => $fi->setFlags(0, 'x'));
$it->rewind();
show('rewind_ok', static fn () => $it->rewind());
show('valid_ok', static fn () => $it->valid());
show('seek_ok', static fn () => $it->seek(0));
show('setFlags_ok', static fn () => $fi->setFlags(FilesystemIterator::CURRENT_AS_FILEINFO));
