/*
 * Stream handle globals and resolver for AOT/JIT stream runtime.
 */

#include <stdint.h>
#include <stdio.h>

#define PHPC_MAX_STREAM_HANDLES 256

FILE *phpc_stream_handles[PHPC_MAX_STREAM_HANDLES];
/** Set when a handle id is allocated; kept after fclose for get_resource_type() (#5179). */
char phpc_stream_was_used[PHPC_MAX_STREAM_HANDLES];
int phpc_stream_chunk_size[PHPC_MAX_STREAM_HANDLES];
int phpc_stream_write_buffer[PHPC_MAX_STREAM_HANDLES];
int phpc_stream_read_buffer[PHPC_MAX_STREAM_HANDLES];
char phpc_stream_write_buffer_storage[PHPC_MAX_STREAM_HANDLES][8192];
char *phpc_stream_paths[PHPC_MAX_STREAM_HANDLES];

FILE *__phpc_resolve_stream(int64_t handle)
{
    if (1 == handle) {
        return stdout;
    }
    if (2 == handle) {
        return stderr;
    }
    if (0 == handle) {
        return stderr;
    }
    if (handle > 0 && handle < PHPC_MAX_STREAM_HANDLES && NULL != phpc_stream_handles[handle]) {
        return phpc_stream_handles[handle];
    }

    return NULL;
}

/* fopen/fread/fwrite/tmpfile helpers: LLVM StreamIoJit.php (#5343 phase 3). */
/* is_resource/fclose/feof/fflush helpers: LLVM StreamLifecycleJit.php (#5343). */
/* get_resource_type/get_resources helpers: LLVM StreamResourceJit.php (#6821). */
/* flock/fseek/fgets/get_contents helpers: LLVM StreamReadJit.php (#5343 phase 4). */
/* stream_set_*_buffer/chunk helpers: LLVM StreamBufferJit.php (#5343 phase 4). */
