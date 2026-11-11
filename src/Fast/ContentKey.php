<?php

namespace Woda\Worktrees\Fast;

use Illuminate\Support\Facades\File;

/**
 * A short key for the content of files and folders: a changed, added or
 * removed file gives a new key. Used for templates, database dumps and the
 * shared app image.
 *
 * The key is the first 12 hex characters of SHA-256 over `$prefix`, then,
 * for each file in the order of its relative path, `path NUL sha256(file) LF`.
 * Hidden files (names that start with a dot) in folders do not count, unless
 * `$hidden` is true (the shared app image: `.dockerignore` and dot files that
 * a Dockerfile copies change the image).
 * CI can compute the same key (see the README).
 */
final class ContentKey
{
    /**
     * @param  list<string>  $paths  files and folders, relative to `$root`
     */
    public static function of(string $root, array $paths, string $prefix = '', bool $hidden = false): string
    {
        $context = hash_init('sha256');
        hash_update($context, $prefix);
        $files = [];
        foreach ($paths as $relative) {
            $path = rtrim($root, '/').'/'.ltrim($relative, '/');
            if (is_file($path)) {
                $files[$relative] = $path;
            } elseif (is_dir($path)) {
                foreach (File::allFiles($path, $hidden) as $file) {
                    $files[$relative.'/'.$file->getRelativePathname()] = $file->getPathname();
                }
            }
        }
        ksort($files);
        foreach ($files as $relative => $path) {
            hash_update($context, $relative."\0".hash_file('sha256', $path)."\n");
        }

        return substr(hash_final($context), 0, 12);
    }
}
