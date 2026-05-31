/*
 * Stream handle helpers for AOT/JIT fwrite() (issue #1070).
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#if defined(_WIN32)
#include <io.h>
#else
#include <sys/file.h>
#include <unistd.h>
#endif


typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHPC_MAX_STREAM_HANDLES 256

static FILE *phpc_stream_handles[PHPC_MAX_STREAM_HANDLES];
static int phpc_stream_chunk_size[PHPC_MAX_STREAM_HANDLES];
static int phpc_stream_write_buffer[PHPC_MAX_STREAM_HANDLES];
static int phpc_stream_read_buffer[PHPC_MAX_STREAM_HANDLES];
static char phpc_stream_write_buffer_storage[PHPC_MAX_STREAM_HANDLES][8192];
static char *phpc_stream_paths[PHPC_MAX_STREAM_HANDLES];

#define PHPC_STREAM_DEFAULT_CHUNK_SIZE 8192
#define PHPC_STREAM_DEFAULT_BUFFER_SIZE 8192

typedef struct __hashtable__ __hashtable__;
extern __hashtable__ *__phpc_stat(__string__ *path, int use_lstat);

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
            phpc_stream_chunk_size[id] = PHPC_STREAM_DEFAULT_CHUNK_SIZE;
            phpc_stream_write_buffer[id] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
            phpc_stream_read_buffer[id] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
            phpc_stream_paths[id] = strdup(phpc_string_data(path));
            if (NULL == phpc_stream_paths[id]) {
                fclose(fp);
                phpc_stream_handles[id] = NULL;

                return -1;
            }

            return id;
        }
    }
    fclose(fp);

    return -1;
}

int64_t __compiler_tmpfile(void)
{
    FILE *fp;
    int64_t id;

    fp = tmpfile();
    if (NULL == fp) {
        return -1;
    }
    for (id = 3; id < PHPC_MAX_STREAM_HANDLES; id++) {
        if (NULL == phpc_stream_handles[id]) {
            phpc_stream_handles[id] = fp;
            phpc_stream_chunk_size[id] = PHPC_STREAM_DEFAULT_CHUNK_SIZE;
            phpc_stream_write_buffer[id] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
            phpc_stream_read_buffer[id] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
            phpc_stream_paths[id] = NULL;

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

int __compiler_is_resource(int64_t handle)
{
    /* fopen() handles start at 3; 1/2 are stdio aliases in phpc_resolve_stream (#3519). */
    if (handle <= 2) {
        return 0;
    }

    return NULL != phpc_resolve_stream(handle) ? 1 : 0;
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
    if (NULL != phpc_stream_paths[handle]) {
        free(phpc_stream_paths[handle]);
        phpc_stream_paths[handle] = NULL;
    }

    return fclose(fp) == 0 ? 1 : 0;
}

/* Map PHP LOCK_* (ext/standard/flock.c) to host flock(2) flags. */
static int phpc_map_flock_operation(int operation)
{
    const int PHP_LOCK_SH = 1;
    const int PHP_LOCK_EX = 2;
    const int PHP_LOCK_UN = 3;
    const int PHP_LOCK_NB = 4;
    int sys_op = 0;

    if ((operation & PHP_LOCK_UN) == PHP_LOCK_UN) {
        sys_op |= LOCK_UN;
        operation &= ~PHP_LOCK_UN;
    }
    if (operation & PHP_LOCK_SH) {
        sys_op |= LOCK_SH;
    }
    if (operation & PHP_LOCK_EX) {
        sys_op |= LOCK_EX;
    }
    if (operation & PHP_LOCK_NB) {
        sys_op |= LOCK_NB;
    }

    return sys_op;
}

int __compiler_flock(int64_t handle, int64_t operation)
{
    FILE *fp;
    int fd;
    int sys_op;

    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return 0;
    }
    fd = fileno(fp);
    if (fd < 0) {
        return 0;
    }
#if defined(_WIN32)
    (void) operation;

    return 0;
#else
    sys_op = phpc_map_flock_operation((int) operation);
    if (0 == sys_op) {
        return 0;
    }

    return flock(fd, sys_op) == 0 ? 1 : 0;
#endif
}

int64_t __compiler_fpassthru(int64_t handle)
{
    FILE *fp;
    char buf[8192];
    size_t got;
    int64_t total = 0;

    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return -1;
    }
    while (1) {
        got = fread(buf, 1, sizeof(buf), fp);
        if (0 == got) {
            if (ferror(fp)) {
                return -1;
            }
            break;
        }
        if (fwrite(buf, 1, got, stdout) != got) {
            return -1;
        }
        total += (int64_t) got;
    }

    return total;
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

int64_t __compiler_stream_set_chunk_size(int64_t handle, int64_t chunk_size)
{
    int previous;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES || NULL == phpc_stream_handles[handle]) {
        return -1;
    }
    if (chunk_size <= 0) {
        return -1;
    }
    previous = phpc_stream_chunk_size[handle];
    if (0 == previous) {
        previous = PHPC_STREAM_DEFAULT_CHUNK_SIZE;
    }
    phpc_stream_chunk_size[handle] = (int) chunk_size;

    return (int64_t) previous;
}

int __compiler_stream_set_timeout(int64_t handle, int64_t seconds, int64_t microseconds)
{
    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES || NULL == phpc_stream_handles[handle]) {
        return 0;
    }
    if (seconds < 0 || microseconds < 0) {
        return 0;
    }
    (void) seconds;
    (void) microseconds;

    /* AOT FILE* table is file-backed; socket timeout is applied in VM via host stream_set_timeout(). */
    return 1;
}

static int phpc_apply_stream_buffer(FILE *fp, int64_t buffer, char *storage, size_t storage_size)
{
    if (0 == buffer) {
        return setvbuf(fp, NULL, _IONBF, 0);
    }
    if (buffer < 0) {
        return setvbuf(fp, NULL, _IOFBF, (size_t) PHPC_STREAM_DEFAULT_BUFFER_SIZE);
    }
    if ((size_t) buffer > storage_size) {
        return setvbuf(fp, NULL, _IOFBF, (size_t) buffer);
    }

    return setvbuf(fp, storage, _IOFBF, (size_t) buffer);
}

int64_t __compiler_stream_set_write_buffer(int64_t handle, int64_t buffer)
{
    FILE *fp;
    int previous;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES || NULL == phpc_stream_handles[handle]) {
        return -1;
    }
    fp = phpc_stream_handles[handle];
    previous = phpc_stream_write_buffer[handle];
    if (0 == previous) {
        previous = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
    }
    if (0 != phpc_apply_stream_buffer(fp, buffer, phpc_stream_write_buffer_storage[handle], sizeof(phpc_stream_write_buffer_storage[handle]))) {
        return -1;
    }
    if (0 == buffer) {
        phpc_stream_write_buffer[handle] = 0;
    } else if (buffer < 0) {
        phpc_stream_write_buffer[handle] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
    } else {
        phpc_stream_write_buffer[handle] = (int) buffer;
    }

    return (int64_t) previous;
}

int64_t __compiler_stream_set_read_buffer(int64_t handle, int64_t buffer)
{
    FILE *fp;
    int previous;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES || NULL == phpc_stream_handles[handle]) {
        return -1;
    }
    fp = phpc_stream_handles[handle];
    previous = phpc_stream_read_buffer[handle];
    if (0 == previous) {
        previous = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
    }
    if (0 != phpc_apply_stream_buffer(fp, buffer, phpc_stream_write_buffer_storage[handle], sizeof(phpc_stream_write_buffer_storage[handle]))) {
        return -1;
    }
    if (0 == buffer) {
        phpc_stream_read_buffer[handle] = 0;
    } else if (buffer < 0) {
        phpc_stream_read_buffer[handle] = PHPC_STREAM_DEFAULT_BUFFER_SIZE;
    } else {
        phpc_stream_read_buffer[handle] = (int) buffer;
    }

    return (int64_t) previous;
}

int __compiler_ftruncate(int64_t handle, int64_t size)
{
    FILE *fp = phpc_resolve_stream(handle);
    int fd;
    int rc;

    if (NULL == fp) {
        return 0;
    }
    if (fflush(fp) != 0) {
        return 0;
    }
    fd = fileno(fp);
    if (fd < 0) {
        return 0;
    }
#if defined(_WIN32)
    rc = _chsize(fd, (long) size);
#else
    rc = ftruncate(fd, (off_t) size);
#endif
    if (rc != 0) {
        return 0;
    }
    clearerr(fp);

    return 1;
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

__string__ *__compiler_fgets(int64_t handle, int64_t length)
{
    FILE *fp;
    char *buf;
    size_t buf_size;
    char *line;

    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return NULL;
    }
    if (0 == length) {
        return NULL;
    }
    if (length < 0) {
        buf_size = 8192;
    } else {
        buf_size = (size_t) length;
    }
    buf = (char *) malloc(buf_size);
    if (NULL == buf) {
        return NULL;
    }
    line = fgets(buf, (int) buf_size, fp);
    if (NULL == line) {
        free(buf);

        return NULL;
    }
    {
        __string__ *result = __string__init((long long) strlen(buf), buf);
        free(buf);

        return result;
    }
}

int64_t __compiler_fseek(int64_t handle, int64_t offset, int64_t whence)
{
    FILE *fp = phpc_resolve_stream(handle);

    if (NULL == fp) {
        return -1;
    }

    return fseek(fp, (long) offset, (int) whence) == 0 ? 0 : -1;
}

/** fstat() metadata via stored fopen path + __phpc_stat (issue #3482). */
__hashtable__ *__phpc_fstat(int64_t handle)
{
    const char *path;
    size_t len;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES) {
        return NULL;
    }
    if (NULL == phpc_stream_handles[handle]) {
        return NULL;
    }
    path = phpc_stream_paths[handle];
    if (NULL == path) {
        return NULL;
    }
    len = strlen(path);

    return __phpc_stat(__string__init((long long) len, path), 0);
}

/** Open stream path for fstat() JIT lowering via stat() (issue #3482). */
__string__ *__phpc_stream_path(int64_t handle)
{
    const char *path;
    size_t len;

    if (handle <= 0 || handle >= PHPC_MAX_STREAM_HANDLES) {
        return NULL;
    }
    if (NULL == phpc_stream_handles[handle]) {
        return NULL;
    }
    path = phpc_stream_paths[handle];
    if (NULL == path) {
        return NULL;
    }
    len = strlen(path);

    return __string__init((long long) len, path);
}

typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);

static char phpc_csv_first_char(__string__ *s, char fallback)
{
    if (NULL == s || 0 == phpc_string_len(s)) {
        return fallback;
    }

    return phpc_string_data(s)[0];
}

static __hashtable__ *phpc_parse_csv_line(const char *line, char delim, char enclosure, char escape)
{
    __hashtable__ *ht;
    size_t field_idx = 0;
    size_t i = 0;
    size_t line_len = strlen(line);
    char *field = NULL;
    size_t field_cap = 0;
    size_t field_len = 0;
    int in_quotes = 0;

    ht = __hashtable__alloc();
    if (NULL == ht) {
        return NULL;
    }

    while (i <= line_len) {
        char c = (i < line_len) ? line[i] : '\0';

        if (in_quotes) {
            if ('\0' == c) {
                break;
            }
            if (c == escape && i + 1 < line_len) {
                if (field_len + 1 >= field_cap) {
                    size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                    char *grown = (char *) realloc(field, new_cap);
                    if (NULL == grown) {
                        free(field);
                        return NULL;
                    }
                    field = grown;
                    field_cap = new_cap;
                }
                field[field_len++] = line[++i];
                ++i;
                continue;
            }
            if (c == enclosure) {
                if (i + 1 < line_len && line[i + 1] == enclosure) {
                    if (field_len + 1 >= field_cap) {
                        size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                        char *grown = (char *) realloc(field, new_cap);
                        if (NULL == grown) {
                            free(field);
                            return NULL;
                        }
                        field = grown;
                        field_cap = new_cap;
                    }
                    field[field_len++] = enclosure;
                    i += 2;
                    continue;
                }
                in_quotes = 0;
                ++i;
                continue;
            }
            if (field_len + 1 >= field_cap) {
                size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                char *grown = (char *) realloc(field, new_cap);
                if (NULL == grown) {
                    free(field);
                    return NULL;
                }
                field = grown;
                field_cap = new_cap;
            }
            field[field_len++] = c;
            ++i;
            continue;
        }

        if ('\0' == c || c == delim) {
            __string__ *str;
            if (NULL == field) {
                str = __string__init(0, "");
            } else {
                str = __string__init((long long) field_len, field);
                free(field);
                field = NULL;
                field_cap = 0;
                field_len = 0;
            }
            if (NULL == str) {
                return NULL;
            }
            __hashtable__setStringAt(ht, field_idx++, str);
            if ('\0' == c) {
                break;
            }
            ++i;
            continue;
        }

        if (c == enclosure) {
            in_quotes = 1;
            ++i;
            continue;
        }

        if (field_len + 1 >= field_cap) {
            size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
            char *grown = (char *) realloc(field, new_cap);
            if (NULL == grown) {
                free(field);
                return NULL;
            }
            field = grown;
            field_cap = new_cap;
        }
        field[field_len++] = c;
        ++i;
    }

    free(field);

    return ht;
}

__hashtable__ *__compiler_fgetcsv(
    int64_t handle,
    int64_t length,
    __string__ *separator,
    __string__ *enclosure,
    __string__ *escape
)
{
    FILE *fp;
    char delim;
    char enc;
    char esc;
    size_t buf_size;
    char *buf;
    char *line;

    fp = phpc_resolve_stream(handle);
    if (NULL == fp) {
        return NULL;
    }
    delim = phpc_csv_first_char(separator, ',');
    enc = phpc_csv_first_char(enclosure, '"');
    esc = phpc_csv_first_char(escape, '\\');
    if (0 == length) {
        return NULL;
    }
    buf_size = length < 0 ? 8192 : (size_t) length;
    buf = (char *) malloc(buf_size);
    if (NULL == buf) {
        return NULL;
    }
    line = fgets(buf, (int) buf_size, fp);
    if (NULL == line) {
        free(buf);

        return NULL;
    }
    {
        size_t n = strlen(buf);
        while (n > 0 && ('\n' == buf[n - 1] || '\r' == buf[n - 1])) {
            buf[--n] = '\0';
        }
        __hashtable__ *result = phpc_parse_csv_line(buf, delim, enc, esc);
        free(buf);

        return result;
    }
}

extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);

/*
 * get_resources() — active fopen/tmpfile handles (php-src basic_functions.c, #3646).
 * type_filter NULL: all streams; "stream": same; any other string is invalid (caller validates).
 */
__hashtable__ *__compiler_get_resources(__string__ *type_filter)
{
    __hashtable__ *ht;
    size_t index = 1;
    int64_t id;

    if (NULL != type_filter) {
        size_t len = phpc_string_len(type_filter);
        const char *data = phpc_string_data(type_filter);
        if (len != 6 || 0 != memcmp(data, "stream", 6)) {
            return NULL;
        }
    }

    ht = __hashtable__alloc();
    if (NULL == ht) {
        return NULL;
    }
    for (id = 3; id < PHPC_MAX_STREAM_HANDLES; id++) {
        if (NULL != phpc_stream_handles[id]) {
            __hashtable__setLongAt(ht, index++, (long long) id);
        }
    }

    return ht;
}
