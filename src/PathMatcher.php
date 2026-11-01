<?php

declare(strict_types=1);

namespace Ambit;

/**
 * Matches path exclusions using Rector's FileInfoMatcher semantics.
 * See rector/rector src/Skipper/Matcher/FileInfoMatcher.php.
 */
final class PathMatcher
{
    public function matches(string $file, string $pattern): bool
    {
        $file = str_replace('\\', '/', $file);
        $pattern = str_replace('\\', '/', $pattern);
        if ($file === $pattern) {
            return true;
        }

        if (substr($pattern, -1) === '*' || substr($pattern, 0, 1) === '*') {
            $pattern = '*' . trim($pattern, '*') . '*';
        } elseif (strpos($pattern, '..') !== false) {
            $resolved = realpath($pattern);
            if ($resolved === false) {
                return false;
            }
            $pattern = str_replace('\\', '/', $resolved);
        }

        if ($pattern === '') {
            return false;
        }
        if (
            strpos($file, $pattern) === 0
            || substr($file, -strlen($pattern)) === $pattern
            || fnmatch($pattern, $file)
            || fnmatch('*/' . $pattern, $file)
        ) {
            return true;
        }

        $resolvedPattern = realpath($pattern);
        $resolvedFile = realpath($file);
        if ($resolvedPattern === false || $resolvedFile === false) {
            return false;
        }

        $resolvedPattern = str_replace('\\', '/', $resolvedPattern);
        $resolvedFile = str_replace('\\', '/', $resolvedFile);
        return $resolvedFile === $resolvedPattern
            || strpos($resolvedFile, rtrim($resolvedPattern, '/') . '/') === 0;
    }
}
