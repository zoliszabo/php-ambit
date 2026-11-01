<?php

declare(strict_types=1);

namespace Ambit\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ChangedFiles
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /** @return string[] Absolute staged, unstaged and untracked paths, including deleted paths. */
    public function getPaths(): array
    {
        // Porcelain paths are relative to the repository root, even from a subdirectory.
        $root = rtrim($this->run(['rev-parse', '--show-toplevel']), "\r\n");
        $entries = explode("\0", $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all']));
        $paths = [];
        for ($i = 0, $count = count($entries); $i < $count; ++$i) {
            $entry = $entries[$i];
            if ($entry === '') {
                continue;
            }
            $paths[] = $root . '/' . substr($entry, 3);
            // NUL-delimited renames/copies give the destination, then the original path.
            if (strpbrk(substr($entry, 0, 2), 'RC') !== false) {
                ++$i;
            }
        }
        return array_values(array_unique($paths));
    }

    /** @param string[] $arguments */
    private function run(array $arguments): string
    {
        $process = new Process(array_merge(['git', '--no-optional-locks', '-C', $this->directory], $arguments));
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Unable to inspect Git changes. ' . trim($process->getErrorOutput()));
        }
        return $process->getOutput();
    }
}
