<?php

declare(strict_types=1);

namespace Ambit;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FileSelector
{
    /**
     * @param string[] $extensions Empty means all extensions.
     * @return string[]
     */
    public function findFiles(Scope $scope, array $extensions = []): array
    {
        $files = [];
        foreach ($scope->resolvePaths() as $path) {
            if ($scope->isExcluded($path)) {
                continue;
            }
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $entry) use ($scope): bool {
                    $realPath = $entry->getRealPath();
                    return $realPath !== false && !$scope->isExcluded($realPath);
                }
            ));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $this->filterFiles($scope, $files, $extensions);
    }

    /**
     * @param string[] $candidates Absolute file paths, e.g. from Git.
     * @param string[] $extensions Empty means all extensions.
     * @return string[]
     */
    public function filterFiles(Scope $scope, array $candidates, array $extensions = []): array
    {
        $roots = $scope->resolvePaths();
        $extensions = array_map('strtolower', $extensions);
        $selected = [];
        foreach ($candidates as $candidate) {
            $file = realpath($candidate);
            if ($file === false || !is_file($file)) {
                continue;
            }
            $file = str_replace('\\', '/', $file);
            if ($scope->isExcluded($file)) {
                continue;
            }
            if ($extensions !== [] && !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }
            foreach ($roots as $root) {
                if ($file === $root || (is_dir($root) && strpos($file, rtrim($root, '/') . '/') === 0)) {
                    $selected[$file] = true;
                    break;
                }
            }
        }
        $files = array_keys($selected);
        sort($files);
        return $files;
    }
}
