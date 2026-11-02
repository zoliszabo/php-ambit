<?php

declare(strict_types=1);

namespace Ambit\Validation;

use Ambit\Config;
use Ambit\PathMatcher;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

/** Checks whether configured paths match existing files or directories. */
final class FilesystemValidator
{
    /**
     * Check real files and directories without constructing a Scope, which
     * would stop at the first missing literal inclusion.
     */
    public function validate(
        Config $config,
        string $directory,
        bool $checkInclusions = true,
        bool $checkExclusions = true
    ): Result {
        if (!is_dir($directory)) {
            return new Result([new Issue(
                'error',
                'filesystem',
                'Project directory does not exist or is not a directory.',
                null,
                null,
                $directory
            )]);
        }
        $issues = [];
        $roots = [$directory];
        foreach ($config->paths() as $pattern) {
            $matches = strpos($pattern, '*') === false ? [$pattern] : (glob($pattern) ?: []);
            $matches = array_filter($matches, static function (string $path): bool {
                return realpath($path) !== false;
            });
            if ($checkInclusions && $matches === []) {
                $issues[] = $this->unmatched('paths', $pattern);
            }
            $roots = array_merge($roots, $matches);
        }

        if (!$checkExclusions || $config->exclusions() === []) {
            return new Result($issues);
        }

        $unmatched = [];
        foreach ($config->exclusions() as $pattern) {
            // A literal path (including an empty directory) is already a match.
            if (realpath($pattern) !== false) {
                continue;
            }
            $unmatched[$pattern] = true;
            $roots = array_merge($roots, $this->searchRoots($pattern));
        }
        if ($unmatched === []) {
            return new Result($issues);
        }

        $matcher = new PathMatcher();
        $complete = true;
        foreach ($this->uniqueRoots($roots) as $root) {
            try {
                foreach ($this->candidates($root) as $candidate) {
                    foreach ($unmatched as $pattern => $_) {
                        if ($matcher->matches($candidate, $pattern)) {
                            unset($unmatched[$pattern]);
                        }
                    }
                    if ($unmatched === []) {
                        break 2;
                    }
                }
            } catch (UnexpectedValueException $exception) {
                $complete = false;
                $issues[] = new Issue(
                    'error',
                    'filesystem',
                    'Cannot inspect paths: ' . $exception->getMessage(),
                    null,
                    null,
                    $root
                );
            }
        }
        // Do not claim a pattern is unmatched when part of its search failed.
        if ($complete) {
            foreach ($unmatched as $pattern => $_) {
                $issues[] = $this->unmatched('exclude', $pattern);
            }
        }
        return new Result($issues);
    }

    private function unmatched(string $key, string $path): Issue
    {
        return new Issue('warning', 'filesystem', 'This does not match any real path.', null, $key, $path);
    }

    /** @return string[] */
    private function searchRoots(string $pattern): array
    {
        $prefix = substr($pattern, 0, strcspn($pattern, '*?['));
        if ($prefix === $pattern) {
            // Literal exclusions also match sibling paths with the same prefix.
            // Glob those siblings rather than scanning an arbitrary parent tree.
            return glob($pattern . '*') ?: [];
        }
        // fnmatch can cross directory boundaries, unlike inclusion globs.
        return [substr($prefix, -1) === '/' ? $prefix : dirname($prefix)];
    }

    /**
     * @param string[] $roots
     * @return string[]
     */
    private function uniqueRoots(array $roots): array
    {
        $existing = [];
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved !== false) {
                $existing[str_replace('\\', '/', $resolved)] = true;
            }
        }
        $roots = array_keys($existing);
        sort($roots);
        $unique = [];
        foreach ($roots as $root) {
            foreach ($unique as $parent) {
                if (is_dir($parent) && strpos($root, rtrim($parent, '/') . '/') === 0) {
                    continue 2;
                }
            }
            $unique[] = $root;
        }
        return $unique;
    }

    /** @return Generator<string> */
    private function candidates(string $root): Generator
    {
        yield $root;
        if (!is_dir($root)) {
            return;
        }
        yield rtrim($root, '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        // Do not follow directory symlinks: they can create traversal cycles.
        foreach ($iterator as $entry) {
            $path = $entry->getRealPath();
            if ($path !== false) {
                $path = str_replace('\\', '/', $path);
                yield $path;
                if ($entry->isDir()) {
                    yield rtrim($path, '/') . '/';
                }
            }
        }
    }
}
