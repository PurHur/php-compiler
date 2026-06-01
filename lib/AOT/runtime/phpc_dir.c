/*
 * Directory handle helpers for opendir/readdir/closedir/rewinddir (issue #3235).
 *
 * php-src: ext/standard/dir.c — listing via scandir(3) for AOT/JIT link stability.
 */

#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <dirent.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHPC_MAX_DIR_HANDLES 256
#define PHPC_DIR_HANDLE_BASE ((int64_t) 0x10000000)

typedef struct {
    __string__ **entries;
    int count;
    int pos;
} phpc_dir_state;

static phpc_dir_state phpc_dir_handles[PHPC_MAX_DIR_HANDLES];

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int64_t phpc_dir_slot(int64_t handle)
{
    if (handle < PHPC_DIR_HANDLE_BASE) {
        return -1;
    }

    return handle - PHPC_DIR_HANDLE_BASE;
}

static phpc_dir_state *phpc_resolve_dir(int64_t handle)
{
    int64_t slot = phpc_dir_slot(handle);
    if (slot <= 0 || slot >= PHPC_MAX_DIR_HANDLES) {
        return NULL;
    }
    if (NULL == phpc_dir_handles[slot].entries) {
        return NULL;
    }

    return &phpc_dir_handles[slot];
}

static void phpc_dir_free_slot(int64_t slot)
{
    int i;

    if (slot <= 0 || slot >= PHPC_MAX_DIR_HANDLES) {
        return;
    }
    if (NULL != phpc_dir_handles[slot].entries) {
        for (i = 0; i < phpc_dir_handles[slot].count; i++) {
            free(phpc_dir_handles[slot].entries[i]);
        }
        free(phpc_dir_handles[slot].entries);
    }
    phpc_dir_handles[slot].entries = NULL;
    phpc_dir_handles[slot].count = 0;
    phpc_dir_handles[slot].pos = 0;
}

int __compiler_is_dir_resource(int64_t handle)
{
    return NULL != phpc_resolve_dir(handle) ? 1 : 0;
}

int64_t __compiler_opendir(__string__ *path)
{
    struct dirent **namelist = NULL;
    int n;
    int64_t id;
    int64_t slot;
    int i;

    if (NULL == path) {
        return -1;
    }
    n = scandir(phpc_string_data(path), &namelist, NULL, alphasort);
    if (n < 0) {
        return -1;
    }
    for (id = 1; id < PHPC_MAX_DIR_HANDLES; id++) {
        slot = id;
        if (NULL == phpc_dir_handles[slot].entries) {
            phpc_dir_handles[slot].entries = (__string__ **) calloc((size_t) n, sizeof(__string__ *));
            if (NULL == phpc_dir_handles[slot].entries) {
                break;
            }
            for (i = 0; i < n; i++) {
                phpc_dir_handles[slot].entries[i] = __string__init(
                    (long long) strlen(namelist[i]->d_name),
                    namelist[i]->d_name
                );
                if (NULL == phpc_dir_handles[slot].entries[i]) {
                    phpc_dir_free_slot(slot);
                    n = -1;
                    break;
                }
            }
            if (n < 0) {
                break;
            }
            for (i = 0; i < n; i++) {
                free(namelist[i]);
            }
            free(namelist);
            phpc_dir_handles[slot].count = n;
            phpc_dir_handles[slot].pos = 0;

            return PHPC_DIR_HANDLE_BASE + id;
        }
    }
    if (NULL != namelist) {
        for (i = 0; i < n; i++) {
            free(namelist[i]);
        }
        free(namelist);
    }

    return -1;
}

__string__ *__compiler_readdir(int64_t handle)
{
    phpc_dir_state *state;

    state = phpc_resolve_dir(handle);
    if (NULL == state || state->pos >= state->count) {
        return NULL;
    }

    return state->entries[state->pos++];
}

int __compiler_closedir(int64_t handle)
{
    int64_t slot = phpc_dir_slot(handle);

    if (slot <= 0 || slot >= PHPC_MAX_DIR_HANDLES) {
        return 0;
    }
    phpc_dir_free_slot(slot);

    return 1;
}

int __compiler_rewinddir(int64_t handle)
{
    phpc_dir_state *state = phpc_resolve_dir(handle);

    if (NULL == state) {
        return 0;
    }
    state->pos = 0;

    return 1;
}
