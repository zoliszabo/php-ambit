<?php

declare(strict_types=1);

namespace Ambit\Tests\Unit;

use Ambit\Config;
use Ambit\Validation\FilesystemValidator;
use Ambit\Validation\SchemaValidator;
use Ambit\Console\Validate;
use Ambit\YamlConfigLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Exception\ParseException;

class ValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ambit-validation-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testSchemaValidationAcceptsParsedDataWithoutCheckingTheFilesystemOrRequiringPhpSet(): void
    {
        $result = (new SchemaValidator())->validate([
            'lint' => ['paths' => ['missing.php'], 'exclude' => ['missing/*']],
        ]);
        self::assertTrue($result->isValid());
        self::assertSame([], $result->issues());
        $file = $this->write('ambit.yaml', "lint: {paths: [missing.php], exclude: ['missing/*']}\n");
        self::assertSame(['lint'], array_keys((new YamlConfigLoader())->loadAll($file)));
    }

    /**
     * @dataProvider schemaErrors
     * @param mixed $data
     */
    public function testSchemaErrorsAreReturnedForAlreadyParsedValues($data, string $message): void
    {
        $result = (new SchemaValidator())->validate($data);
        self::assertFalse($result->isValid());
        self::assertCount(1, $result->errors());
        self::assertSame([], $result->warnings());
        $issue = $result->errors()[0];
        self::assertSame('schema', $issue->type());
        self::assertStringContainsString($message, $issue->message());
    }

    public static function schemaErrors(): array
    {
        return [
            'null' => [null, 'mapping of named sets'],
            'boolean' => [false, 'mapping of named sets'],
            'number' => [42, 'mapping of named sets'],
            'string' => ['src', 'mapping of named sets'],
            'unparsed YAML string' => ['php: {}', 'mapping of named sets'],
            'object' => [new \stdClass(), 'mapping of named sets'],
            'empty mapping' => [[], 'at least one named set'],
            'list of sets' => [[[]], 'must map non-empty string names'],
            'unknown key' => [['php' => ['excludes' => []]], 'Unknown scope configuration keys'],
            'invalid list' => [['php' => ['paths' => ['name' => 'src']]], 'must be a list'],
            'null paths' => [['php' => ['paths' => null]], 'must be a list'],
            'empty path' => [['php' => ['paths' => ['']]], 'must be non-empty strings'],
            'unknown parent' => [['php' => ['extends' => 'absent']], 'Unknown parent'],
            'cycle' => [['php' => ['extends' => 'php']], 'Circular'],
            'invalid unused set' => [['php' => [], 'unused' => ['extends' => 'absent']], 'Unknown parent'],
        ];
    }

    public function testYamlParsingFailsBeforeSchemaValidation(): void
    {
        // This document also has an unknown definition key. Parsing must fail first.
        $file = $this->write('ambit.yaml', 'php: {unknown: [], paths: [');
        $this->expectException(ParseException::class);
        (new YamlConfigLoader())->loadAll($file);
    }

    public function testFileValidationDistinguishesYamlSyntaxFromSchemaErrors(): void
    {
        $validator = new Validate();
        $syntax = $validator->validateFile($this->write('ambit.yaml', 'php: {paths: ['));
        self::assertFalse($syntax->isValid());
        self::assertSame('syntax', $syntax->errors()[0]->type());
        self::assertSame([], $syntax->warnings());

        $schema = $validator->validateFile($this->write('ambit.yaml', "php: {extends: missing}\n"));
        self::assertFalse($schema->isValid());
        self::assertSame('schema', $schema->errors()[0]->type());
        self::assertSame([], $schema->warnings());

        $scalar = $validator->validateFile($this->write('ambit.yaml', "false\n"));
        self::assertSame('schema', $scalar->errors()[0]->type());
    }

    public function testMissingConfigurationAndUnknownSelectedSetAreReported(): void
    {
        $validator = new Validate();
        self::assertFalse($validator->validateFile($this->root . '/missing.yaml')->isValid());
        $file = $this->write('ambit.yaml', "php: {}\n");
        $result = $validator->validateFile($file, 'missing');
        self::assertStringContainsString('Unknown scope set "missing"', $result->errors()[0]->message());
        self::assertSame('selection', $result->errors()[0]->type());
        self::assertSame('missing', $result->errors()[0]->set());
        self::assertTrue((new SchemaValidator())->validate(['php' => []])->isValid());
    }

    public function testReportsAllMissingEntriesWithSetKeyAndAbsolutePath(): void
    {
        $file = $this->write('project/ambit.yaml', <<<'YAML'
php:
    paths: [missing.php, 'modules/*/src']
    exclude: [missing/cache/, '*/generated/*']
lint:
    extends: php
YAML
        );
        $result = (new Validate())->validateFile($file);
        self::assertTrue($result->isValid());
        self::assertCount(8, $result->warnings());
        $issue = $result->warnings()[0];
        self::assertSame('warning', $issue->severity());
        self::assertSame('filesystem', $issue->type());
        self::assertSame('php', $issue->set());
        self::assertSame('paths', $issue->key());
        self::assertSame($this->root . '/project/missing.php', $issue->path());
        self::assertSame('missing.php', $issue->originalEntry());
        self::assertSame('lint', $result->warnings()[4]->set());
        self::assertSame('missing.php', $result->warnings()[4]->originalEntry());
        self::assertSame('exclude', $result->warnings()[7]->key());
        self::assertSame('*/generated/*', $result->warnings()[7]->originalEntry());
    }

    public function testFilesystemCanBeLimitedToOneSetAndOneKindOfPath(): void
    {
        $file = $this->write('ambit.yaml', "php: {paths: [missing], exclude: [absent]}\nlint: {paths: [other]}\n");
        $validator = new Validate();
        $paths = $validator->validateFile($file, 'php', true, false);
        self::assertCount(1, $paths->warnings());
        self::assertSame('paths', $paths->warnings()[0]->key());
        $exclusions = $validator->validateFile($file, 'php', false, true);
        self::assertCount(1, $exclusions->warnings());
        self::assertSame('exclude', $exclusions->warnings()[0]->key());
    }

    public function testInclusionChecksUseGlobWithoutRecursiveGlobstarOrQuestionMarkOnly(): void
    {
        $this->write('modules/one/nested/src/code.php');
        $this->write('src/a.php');
        $file = $this->write('ambit.yaml', <<<'YAML'
php:
    paths: [src, src/a.php, 'modules/**/src', 'modules/*/*/src', 'src/?.php', 'src/*.php']
YAML
        );
        $result = (new Validate())->validateFile($file);
        self::assertSame([
            $this->root . '/modules/**/src',
            $this->root . '/src/?.php',
        ], array_map(static function ($issue): ?string {
            return $issue->path();
        }, $result->warnings()));
    }

    public function testExclusionsUsePrefixFnmatchAndInspectDirectoriesAndHiddenPaths(): void
    {
        $this->write('src/cache-helper.php');
        $this->write('src/deep/nested/old.php');
        $this->write('src/.hidden/keep.php');
        mkdir($this->root . '/src/empty');
        mkdir($this->root . '/empty');
        $file = $this->write('ambit.yaml', <<<'YAML'
php:
    exclude:
        - src/cache
        - src/cache/
        - 'src/*/o?d.[p]hp'
        - '*/.hidden/*'
        - '*/empty/'
        - empty/
YAML
        );
        $result = (new Validate())->validateFile($file);
        self::assertCount(1, $result->warnings());
        self::assertSame($this->root . '/src/cache/', $result->warnings()[0]->path());
    }

    public function testExclusionsCanMatchOutsideInclusionsAndStillMatchExcludedSubtrees(): void
    {
        $this->write('src/code.php');
        $this->write('tests/deep/test.php');
        $file = $this->write('ambit.yaml', "php: {paths: [src], exclude: [tests, 'tests/*/*.php']}\n");
        self::assertSame([], (new Validate())->validateFile($file)->issues());
    }

    public function testExternalAbsolutePatternsAndRootsAreChecked(): void
    {
        $this->write('external/modules/one/nested/cache/file.php');
        $this->write('external/src-old/file.php');
        $file = $this->write('project/ambit.yaml',
            "php:\n    paths: ['../external/modules/*']\n    exclude:\n"
            . "        - '" . $this->root . "/external/modules/*/cache/*'\n"
            . "        - '" . $this->root . "/external/src'\n"
        );
        self::assertSame([], (new Validate())->validateFile($file)->issues());
    }

    public function testFilesystemValidationCanBeUsedWithAProgrammaticallyCreatedConfig(): void
    {
        $this->write('src/code.php');
        $config = new Config([$this->root . '/src', $this->root . '/missing.php'], [$this->root . '/missing/']);
        $result = (new FilesystemValidator())->validate($config, $this->root);
        self::assertCount(2, $result->warnings());
        self::assertNull($result->warnings()[0]->set());
        self::assertNull($result->warnings()[0]->originalEntry());
        $result = (new FilesystemValidator())->validate($config, $this->root . '/absent');
        self::assertFalse($result->isValid());
        self::assertSame('filesystem', $result->errors()[0]->type());
    }

    public function testDirectorySymlinksDoNotCauseTraversalCycles(): void
    {
        $this->write('src/code.php');
        symlink($this->root, $this->root . '/src/loop');
        $file = $this->write('ambit.yaml', "php: {exclude: ['*/absent/*']}\n");
        self::assertCount(1, (new Validate())->validateFile($file)->warnings());
    }

    public function testUnreadableDirectoriesProduceErrorsWithoutFalseUnmatchedWarnings(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Requires an unprivileged POSIX user.');
        }
        $this->write('private/code.php');
        $file = $this->write('ambit.yaml', "php: {exclude: ['*/absent/*']}\n");
        chmod($this->root . '/private', 0000);
        try {
            $result = (new Validate())->validateFile($file);
            self::assertFalse($result->isValid());
            self::assertCount(1, $result->errors());
            self::assertSame('filesystem', $result->errors()[0]->type());
            self::assertSame([], $result->warnings());
        } finally {
            chmod($this->root . '/private', 0700);
        }
    }

    public function testCliWarningsFailWhileSchemaOnlyCanSucceed(): void
    {
        $this->write('ambit.yaml', "lint: {paths: [missing], exclude: [absent]}\n");
        [$status, $output, $error] = $this->cli(['validate']);
        self::assertSame(3, $status);
        self::assertSame('', $error);
        self::assertStringStartsWith("Validating \"ambit.yaml\".\n", $output);
        self::assertStringContainsString('WARNING [filesystem] lint.paths', $output);
        self::assertStringContainsString('0 error(s), 2 warning(s).', $output);
        [$status, $output] = $this->cli(['validate', '--schema-only']);
        self::assertSame(0, $status);
        self::assertStringStartsWith("Validating \"ambit.yaml\".\n", $output);
        self::assertStringContainsString('0 error(s), 0 warning(s).', $output);
    }

    public function testCliSelectedSetAndPathOptions(): void
    {
        $file = $this->write('config/ambit.yaml', "php: {paths: [missing], exclude: [absent]}\nlint: {}\n");
        [$status, $output] = $this->cli(['validate', $file, '--set=lint']);
        self::assertSame(0, $status);
        self::assertStringStartsWith('Validating "' . $file . '".' . "\n", $output);
        self::assertStringContainsString('0 warning(s)', $output);
        foreach (['--paths-only' => 'paths', '--exclusions-only' => 'exclude'] as $option => $key) {
            [$status, $output] = $this->cli(['validate', $file, '--set=php', $option]);
            self::assertSame(3, $status);
            self::assertStringContainsString('php.' . $key, $output);
            self::assertStringContainsString($this->root . '/config/', $output);
            self::assertStringContainsString('1 warning(s)', $output);
        }
    }

    public function testCliPreservesOriginalEntriesThroughInheritanceAndNormalization(): void
    {
        $absolute = $this->root . '/config/missing.php';
        $file = $this->write('config/ambit.yaml', <<<'YAML'
base:
    paths: [missing.php, 'missing\file.php', 'modules/*/src', ./missing.php]
    exclude: ['../cache/']
php:
    extends: base
YAML
            . "\n    paths: ['" . $absolute . "']\nabsolute:\n    paths: ['" . $absolute . "']\n"
        );
        [$status, $output, $error] = $this->cli(['validate', $file, '--set=php']);
        self::assertSame(3, $status);
        self::assertSame('', $error);
        foreach ([
            ['paths', 'missing.php', 'missing.php'],
            ['paths', 'missing\\file.php', 'missing/file.php'],
            ['paths', 'modules/*/src', 'modules/*/src'],
            ['paths', './missing.php', './missing.php'],
            ['exclude', '../cache/', '../cache/'],
        ] as [$key, $original, $resolved]) {
            self::assertStringContainsString(
                'WARNING [filesystem] php.' . $key . ' "' . $original . '" (resolved to "'
                . $this->root . '/config/' . $resolved . '"): This does not match any real path.',
                $output
            );
        }
        self::assertStringContainsString('0 error(s), 5 warning(s).', $output);

        [$status, $output] = $this->cli(['validate', $file, '--set=absolute']);
        self::assertSame(3, $status);
        self::assertStringContainsString(
            'WARNING [filesystem] absolute.paths "' . $absolute . '": This does not match any real path.',
            $output
        );
        self::assertStringNotContainsString('resolved to', $output);
    }

    public function testLoaderOriginalEntriesBelongToTheMostRecentlyLoadedFile(): void
    {
        $loader = new YamlConfigLoader();
        $file = $this->write('ambit.yaml', "php: {paths: [missing.php]}\n");
        $loader->loadAll($file);
        self::assertSame('missing.php', $loader->originalEntry('php', 'paths', $this->root . '/missing.php'));

        $other = $this->write('other.yaml', "php: {}\n");
        $loader->loadAll($other);
        self::assertNull($loader->originalEntry('php', 'paths', $this->root . '/missing.php'));
    }

    public function testCliSchemaFailuresUsageErrorsAndHelp(): void
    {
        $this->write('ambit.yaml', "php: {extends: missing}\n");
        [$status, $output] = $this->cli(['validate']);
        self::assertSame(1, $status);
        self::assertStringStartsWith("Validating \"ambit.yaml\".\n", $output);
        self::assertStringContainsString('ERROR [schema]', $output);
        self::assertStringContainsString('1 error(s), 0 warning(s)', $output);
        self::assertSame(2, $this->cli(['validate', '--unknown'])[0]);
        self::assertSame(2, $this->cli(['validate', '--schema-only', '--paths-only'])[0]);
        self::assertSame(2, $this->cli(['validate', '--set='])[0]);
        self::assertSame(2, $this->cli(['unknown'])[0]);
        [$status, $output] = $this->cli(['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('Usage: ambit validate', $output);
    }

    public function testCliErrorsTakePriorityOverWarnings(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Requires an unprivileged POSIX user.');
        }
        $this->write('private/code.php');
        $this->write('ambit.yaml', "php: {paths: [missing.php], exclude: ['*/absent/*']}\n");
        chmod($this->root . '/private', 0000);
        try {
            [$status, $output] = $this->cli(['validate']);
            self::assertSame(1, $status);
            self::assertStringContainsString('ERROR [filesystem]', $output);
            self::assertStringContainsString('WARNING [filesystem]', $output);
            self::assertStringContainsString('1 error(s), 1 warning(s)', $output);
        } finally {
            chmod($this->root . '/private', 0700);
        }
    }

    public function testCliUnknownSelectedSetIsASelectionError(): void
    {
        $this->write('ambit.yaml', "php: {}\n");
        [$status, $output] = $this->cli(['validate', '--set=missing']);
        self::assertSame(1, $status);
        self::assertStringContainsString('ERROR [selection] missing', $output);
        self::assertStringContainsString('Unknown scope set "missing"', $output);
    }

    public function testInstalledBinaryCanFindTheConsumingProjectsAutoloader(): void
    {
        $this->write('ambit.yaml', "php: {}\n");
        $binary = $this->write('installed/vendor/zoliszabo/php-ambit/bin/ambit',
            file_get_contents(dirname(__DIR__, 2) . '/bin/ambit')
        );
        $this->write('installed/vendor/autoload.php',
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
        );
        [$status, $output, $error] = $this->cli(['validate'], $binary);
        self::assertSame(0, $status);
        self::assertSame('', $error);
        self::assertStringContainsString('0 error(s), 0 warning(s)', $output);
    }

    /** @return array{int, string, string} */
    private function cli(array $arguments, ?string $binary = null): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, $binary ?? dirname(__DIR__, 2) . '/bin/ambit'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    private function write(string $path, string $contents = "<?php\n"): string
    {
        $path = $this->root . '/' . $path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }
}
