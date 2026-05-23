/*
 * Stream handle helpers for AOT/JIT fwrite() (issue #1070).
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>


typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHPC_MAX_STREAM_HANDLES 256

static FILE *phpc_stream_handles[PHPC_MAX_STREAM_HANDLES];

static size_t phpc_string_len(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static FILE *phpc_resolve_stream(int64_t handle)
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

int64_t __compiler_fwrite(int64_t handle, __string__ *data, int64_t length)
{
    FILE *fp = phpc_resolve_stream(handle);
    if (NULL == fp || NULL == data) {
        return -1;
    }

    size_t data_len = phpc_string_len(data);
    size_t write_len = data_len;
    if (length >= 0 && (size_t) length < data_len) {
        write_len = (size_t) length;
    }
    if (0 == write_len) {
        return 0;
    }

    size_t n = fwrite(phpc_string_data(data), 1, write_len, fp);
    if (n != write_len) {
        return -1;
    }

    return (int64_t) n;
}

int64_t __compiler_fopen(__string__ *path, __string__ *mode)
{
    FILE *fp;
    int64_t id;

    if (NULL == path || NULL == mode) {
        return -1;
    }
    fp = fopen(phpc_string_data(path), phpc_string_data(mode));
    if (NULL == fp) {
        return -1;
    }
    for (id = 3; id < PHPC_MAX_STREAM_HANDLES; id++) {
        if (NULL == phpc_stream_handles[id]) {
            phpc_stream_handles[id] = fp;

            return id;
        }
    }
    fclose(fp);

    return -1;
}

__string__ *__compiler_fread(int64_t handle, int64_t length)
{
    FILE *fp;
    char *buf;
    size_t got;
    __string__ *result;

    if (length < 0) {
        return NULL;
    }
    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return NULL;
    }
    if (0 == length) {
        return __string__init(0, "");
    }
    buf = (char *) malloc((size_t) length);
    if (NULL == buf) {
        return NULL;
    }
    got = fread(buf, 1, (size_t) length, fp);
    if (0 == got && ferror(fp)) {
        free(buf);

        return NULL;
    }
    result = __string__init((long long) got, buf);
    free(buf);

    return result;
}

int __compiler_fclose(int64_t handle)
{
    FILE *fp;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES) {
        return 0;
    }
    fp = phpc_stream_handles[handle];
    if (NULL == fp) {
        return 0;
    }
    phpc_stream_handles[handle] = NULL;

    return fclose(fp) == 0 ? 1 : 0;
}

int __compiler_feof(int64_t handle)
{
    FILE *fp = phpc_resolve_stream(handle);

    if (NULL == fp) {
        return 1;
    }

    return feof(fp) ? 1 : 0;
}

int __compiler_fflush(int64_t handle)
{
    FILE *fp = phpc_resolve_stream(handle);

    if (NULL == fp) {
        return 0;
    }

    return fflush(fp) == 0 ? 1 : 0;
}

int64_t __compiler_ftell(int64_t handle)
{
    FILE *fp = phpc_resolve_stream(handle);
    long pos;

    if (NULL == fp) {
        return -1;
    }
    pos = ftell(fp);
    if (pos < 0) {
        return -1;
    }

    return (int64_t) pos;
}

__string__ *__compiler_fgetc(int64_t handle)
{
    FILE *fp;
    int c;
    char buf[2];

    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return NULL;
    }
    c = fgetc(fp);
    if (EOF == c) {
        if (feof(fp)) {
            return __string__init(0, "");
        }

        return NULL;
    }
    buf[0] = (char) c;
    buf[1] = '\0';

    return __string__init(1, buf);
}
