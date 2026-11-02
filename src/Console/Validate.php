<?php

declare(strict_types=1);

namespace Ambit\Console;

use Ambit\Exception\UnknownSetException;
use Ambit\Validation\FilesystemValidator;
use Ambit\Validation\Issue;
use Ambit\Validation\Result;
use Ambit\YamlConfigLoader;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;

final class Validate
{
    /**
     * Schema validation always checks every set. Filesystem validation checks all
     * sets unless $set is specified. Disable both path checks for schema only.
     */
    public function validateFile(
        string $file,
        ?string $set = null,
        bool $checkInclusions = true,
        bool $checkExclusions = true
    ): Result {
        try {
            $loader = new YamlConfigLoader();
            $configs = $set === null ? $loader->loadAll($file) : [$set => $loader->load($file, $set)];
        } catch (ParseException $exception) {
            return new Result([new Issue('error', 'syntax', $exception->getMessage())]);
        } catch (UnknownSetException $exception) {
            return new Result([new Issue('error', 'selection', $exception->getMessage(), $set)]);
        } catch (InvalidArgumentException $exception) {
            return new Result([new Issue('error', 'schema', $exception->getMessage())]);
        }
        if (!$checkInclusions && !$checkExclusions) {
            return new Result();
        }

        $issues = [];
        $validator = new FilesystemValidator();
        $directory = str_replace('\\', '/', (string) realpath(dirname($file)));
        foreach ($configs as $name => $config) {
            $result = $validator->validate($config, $directory, $checkInclusions, $checkExclusions);
            foreach ($result->issues() as $issue) {
                $issues[] = new Issue(
                    $issue->severity(),
                    $issue->type(),
                    $issue->message(),
                    $name,
                    $issue->key(),
                    $issue->path(),
                    $issue->key() !== null && $issue->path() !== null
                        ? $loader->originalEntry($name, $issue->key(), $issue->path())
                        : null
                );
            }
        }
        return new Result($issues);
    }
}
