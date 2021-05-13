<?php

declare(strict_types=1);

namespace League\Flysystem\AdapterTestUtilities;

use const PHP_EOL;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use PHPUnit\Framework\TestCase;
use Throwable;
use function file_get_contents;

/**
 * @codeCoverageIgnore
 */
abstract class FilesystemAdapterTestCase extends TestCase
{
    use RetryOnTestException;

    /**
     * @var AdapterInterface
     */
    protected static $adapter;

    /**
     * @var bool
     */
    private $isUsingCustomAdapter = false;

    public static function clearFilesystemAdapterCache(): void
    {
        static::$adapter = null;
    }

    abstract protected static function createFilesystemAdapter(): AdapterInterface;

    public function adapter(): AdapterInterface
    {
        if ( ! static::$adapter instanceof AdapterInterface) {
            static::$adapter = static::createFilesystemAdapter();
        }

        return static::$adapter;
    }

    public static function tearDownAfterClass(): void
    {
        self::clearFilesystemAdapterCache();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter();
    }

    protected function useAdapter(AdapterInterface $adapter): AdapterInterface
    {
        static::$adapter = $adapter;
        $this->isUsingCustomAdapter = true;

        return $adapter;
    }

    /**
     * @after
     */
    public function cleanupAdapter(): void
    {
        $this->clearStorage();
        $this->clearCustomAdapter();
    }

    public function clearStorage(): void
    {
        reset_function_mocks();

        try {
            $adapter = $this->adapter();
        } catch (Throwable $exception) {
            /*
             * Setting up the filesystem adapter failed. This is OK at this stage.
             * The exception will have been shown to the user when trying to run
             * a test. We expect an exception to be thrown when tests are marked as
             * skipped when a filesystem adapter cannot be constructed.
             */
            return;
        }

        foreach ($adapter->listContents('', false) as $item) {
            if ($item['type'] === 'dir') {
                $adapter->deleteDir($item['path']);
            } else {
                $adapter->delete($item['path']);
            }
        }
    }

    public function clearCustomAdapter(): void
    {
        if ($this->isUsingCustomAdapter) {
            $this->isUsingCustomAdapter = false;
            self::clearFilesystemAdapterCache();
        }
    }

    /**
     * @test
     */
    public function writing_and_reading_with_string(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'contents', new Config());
            $fileExists = $adapter->has('path.txt');
            $contents = $adapter->read('path.txt');

            $this->assertNotNull($fileExists);
            $this->assertNotFalse($fileExists);
            $this->assertEquals('contents', $contents['contents']);
        });
    }

    /**
     * @test
     */
    public function writing_a_file_with_a_stream(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $writeStream = stream_with_contents('contents');

            $adapter->writeStream('path.txt', $writeStream, new Config());
            $fileExists = $adapter->has('path.txt');

            $this->assertNotNull($fileExists);
            $this->assertNotFalse($fileExists);
        });
    }

    /**
     * @test
     * @dataProvider filenameProvider
     */
    public function writing_and_reading_files_with_special_path(string $path): void
    {
        $this->runScenario(function () use ($path) {
            $adapter = $this->adapter();

            $adapter->write($path, 'contents', new Config());
            $contents = $adapter->read($path);

            $this->assertEquals('contents', $contents['contents']);
        });
    }

    public function filenameProvider(): iterable
    {
        yield "a path with square brackets in filename 1" => ["some/file[name].txt"];
        yield "a path with square brackets in filename 2" => ["some/file[0].txt"];
        yield "a path with square brackets in filename 3" => ["some/file[10].txt"];
        yield "a path with square brackets in dirname 1" => ["some[name]/file.txt"];
        yield "a path with square brackets in dirname 2" => ["some[0]/file.txt"];
        yield "a path with square brackets in dirname 3" => ["some[10]/file.txt"];
        yield "a path with curly brackets in filename 1" => ["some/file{name}.txt"];
        yield "a path with curly brackets in filename 2" => ["some/file{0}.txt"];
        yield "a path with curly brackets in filename 3" => ["some/file{10}.txt"];
        yield "a path with curly brackets in dirname 1" => ["some{name}/filename.txt"];
        yield "a path with curly brackets in dirname 2" => ["some{0}/filename.txt"];
        yield "a path with curly brackets in dirname 3" => ["some{10}/filename.txt"];
        yield "a path with space in dirname" => ["some dir/filename.txt"];
        yield "a path with space in filename" => ["somedir/file name.txt"];
    }

    /**
     * @test
     */
    public function writing_a_file_with_an_empty_stream(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $writeStream = stream_with_contents('');

            $adapter->writeStream('path.txt', $writeStream, new Config());
            $fileExists = $adapter->has('path.txt');

            $this->assertNotNull($fileExists);
            $this->assertNotFalse($fileExists);

            $contents = $adapter->read('path.txt');
            $this->assertEquals('', $contents['contents']);
        });
    }

    /**
     * @test
     */
    public function reading_a_file(): void
    {
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');

        $this->runScenario(function () {
            $contents = $this->adapter()->read('path.txt');

            $this->assertEquals('contents', $contents['contents']);
        });
    }

    /**
     * @test
     */
    public function reading_a_file_with_a_stream(): void
    {
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');

        $this->runScenario(function () {
            $readStream = $this->adapter()->readStream('path.txt');
            $contents = stream_get_contents($readStream['stream']);

            $this->assertIsResource($readStream['stream']);
            $this->assertEquals('contents', $contents);
            fclose($readStream['stream']);
        });
    }

    /**
     * @test
     */
    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => 'public']);
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config(['visibility' => 'private']));

            $contents = $adapter->read('path.txt');
            $this->assertEquals('new contents', $contents['contents']);

            if (! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            )) {
                $visibility = $adapter->getVisibility('path.txt')['visibility'];
                $this->assertEquals('private', $visibility);
            }
        });
    }

    /**
     * @test
     */
    public function deleting_a_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $this->givenWeHaveAnExistingFile('path.txt', 'contents');

            $adapter->delete('path.txt');
            $fileExists = $adapter->has('path.txt');

            $this->assertFalse($fileExists);
        });
    }

    /**
     * @test
     */
    public function listing_contents_shallow(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('some/0-path.txt', 'contents');
            $this->givenWeHaveAnExistingFile('some/1-nested/path.txt', 'contents');

            $items = $this->adapter()->listContents('some', false);

            $this->assertIsArray($items);
            $this->assertCount(2, $items, $this->formatIncorrectListingCount($items));

            // Order of entries is not guaranteed
            [$fileIndex, $directoryIndex] = $items[0]['type'] === 'file' ? [0, 1] : [1, 0];

            $this->assertEquals('some/0-path.txt', $items[$fileIndex]['path']);
            $this->assertEquals('some/1-nested', $items[$directoryIndex]['path']);
            $this->assertTrue($items[$fileIndex]['type'] === 'file');
            $this->assertTrue($items[$directoryIndex]['type'] === 'dir');
        });
    }

    /**
     * @test
     */
    public function listing_contents_recursive(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->createDir('path', new Config());
            $adapter->write('path/file.txt', 'string', new Config());

            $items = $adapter->listContents('', true);
            $this->assertCount(2, $items, $this->formatIncorrectListingCount($items));
        });
    }

    protected function formatIncorrectListingCount(array $items): string
    {
        $message = "Incorrect number of items returned.\nThe listing contains:\n\n";

        foreach ($items as $item) {
            $message .= "- {$item['path']}\n";
        }

        return $message . PHP_EOL;
    }

    protected function givenWeHaveAnExistingFile(string $path, string $contents = 'contents', array $config = []): void
    {
        $this->runScenario(function () use ($path, $contents, $config) {
            $this->adapter()->write($path, $contents, new Config($config));
        });
    }

    /**
     * @test
     */
    public function fetching_file_size(): void
    {
        $adapter = $this->adapter();
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');

        $this->runScenario(function () use ($adapter) {
            $attributes = $adapter->getSize('path.txt');
            $this->assertIsArray($attributes);
            $this->assertEquals(8, $attributes['size']);
        });
    }

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => 'public']);

            if (! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            )) {
                $this->assertEquals('public', $adapter->getVisibility('path.txt')['visibility']);

                $adapter->setVisibility('path.txt', 'private');

                $this->assertEquals('private', $adapter->getVisibility('path.txt')['visibility']);

                $adapter->setVisibility('path.txt', 'public');

                $this->assertEquals('public', $adapter->getVisibility('path.txt')['visibility']);
            } else {
                $this->expectException(\LogicException::class);

                $adapter->getVisibility('path.txt');
            }
        });
    }

    /**
     * @test
     */
    public function fetching_file_size_of_a_directory(): void
    {
        $adapter = $this->adapter();

        $this->runScenario(function () use ($adapter) {
            $adapter->createDir('path', new Config());
            $size = $adapter->getSize('path/');

            $this->assertFalse($size);
        });
    }

    /**
     * @test
     */
    public function fetching_file_size_of_non_existing_file(): void
    {
        $this->runScenario(function () {
            $size = $this->adapter()->getSize('non-existing-file.txt');

            $this->assertFalse($size);
        });
    }

    /**
     * @test
     */
    public function fetching_last_modified_of_non_existing_file(): void
    {
        $this->runScenario(function () {
            $timestamp = $this->adapter()->getTimestamp('non-existing-file.txt');

            $this->assertFalse($timestamp);
        });
    }

    /**
     * @test
     */
    public function fetching_visibility_of_non_existing_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $supportsVisibility = ! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            );

            if (! $supportsVisibility) {
                $this->expectException(\LogicException::class);
            }

            $visibility = $this->adapter()->getVisibility('non-existing-file.txt');

            if ($supportsVisibility) {
                $this->assertFalse($visibility);
            }
        });
    }

    /**
     * @test
     */
    public function fetching_the_mime_type_of_an_svg_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('file.svg', file_get_contents(__DIR__ . '/test_files/flysystem.svg'));

            $attributes = $this->adapter()->getMimetype('file.svg');

            $this->assertStringStartsWith('image/svg', $attributes['mimetype']);
        });
    }

    /**
     * @test
     */
    public function fetching_mime_type_of_non_existing_file(): void
    {
        $this->runScenario(function () {
            $attributes = $this->adapter()->getMimetype('non-existing-file.txt');

            $this->assertFalse($attributes);
        });
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->givenWeHaveAnExistingFile(
            'unknown-mime-type.md5',
            file_get_contents(__DIR__ . '/test_files/unknown-mime-type.md5')
        );

        $this->runScenario(function () {
            $attributes = $this->adapter()->getMimetype('unknown-mime-type.md5');

            $this->assertFalse($attributes);
        });
    }

    /**
     * @test
     */
    public function listing_a_toplevel_directory(): void
    {
        $this->givenWeHaveAnExistingFile('path1.txt');
        $this->givenWeHaveAnExistingFile('path2.txt');

        $this->runScenario(function () {
            $contents = $this->adapter()->listContents('', true);

            $this->assertCount(2, $contents);
        });
    }

    /**
     * @test
     */
    public function writing_and_reading_with_streams(): void
    {
        $this->runScenario(function () {
            $writeStream = stream_with_contents('contents');
            $adapter = $this->adapter();

            $adapter->writeStream('path.txt', $writeStream, new Config());
            if (is_resource($writeStream)) {
                fclose($writeStream);
            };
            $readStream = $adapter->readStream('path.txt');

            $this->assertIsResource($readStream['stream']);
            $contents = stream_get_contents($readStream['stream']);
            fclose($readStream['stream']);
            $this->assertEquals('contents', $contents);
        });
    }

    /**
     * @test
     */
    public function setting_visibility_on_a_file_that_does_not_exist(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $supportsVisibility = ! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            );

            if (! $supportsVisibility) {
                $this->expectException(\LogicException::class);
            }

            $attributes = $adapter->setVisibility('path.txt', 'private');

            if ($supportsVisibility) {
                $this->assertFalse($attributes);
            }
        });
    }

    /**
     * @test
     */
    public function copying_a_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config(['visibility' => 'public'])
            );

            $adapter->copy('source.txt', 'destination.txt');

            $sourceFile = $adapter->has('source.txt');
            $destinationFile = $adapter->has('destination.txt');
            $this->assertNotFalse($sourceFile);
            $this->assertNotNull($sourceFile);
            $this->assertNotFalse($destinationFile);
            $this->assertNotNull($destinationFile);
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt')['contents']);

            if (! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            )) {
                $this->assertEquals('public', $adapter->getVisibility('destination.txt')['visibility']);
            }
        });
    }

    /**
     * @test
     */
    public function copying_a_file_again(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config(['visibility' => 'public'])
            );

            $adapter->copy('source.txt', 'destination.txt');

            $sourceFile = $adapter->has('source.txt');
            $destinationFile = $adapter->has('destination.txt');
            $this->assertNotFalse($sourceFile);
            $this->assertNotNull($sourceFile);
            $this->assertNotFalse($destinationFile);
            $this->assertNotNull($destinationFile);
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt')['contents']);

            if (! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            )) {
                $this->assertEquals('public', $adapter->getVisibility('destination.txt')['visibility']);
            }
        });
    }

    /**
     * @test
     */
    public function moving_a_file(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write(
                'source.txt',
                'contents to be copied',
                new Config(['visibility' => 'public'])
            );
            $adapter->rename('source.txt', 'destination.txt');

            $sourceFile = $adapter->has('source.txt');
            $destinationFile = $adapter->has('destination.txt');

            $this->assertFalse(
                $sourceFile,
                'After moving a file should no longer exist in the original location.'
            );
            $this->assertNotFalse(
                $destinationFile,
                'After moving, a file should be present at the new location.'
            );
            $this->assertEquals('contents to be copied', $adapter->read('destination.txt')['contents']);

            if (! in_array(
                NotSupportingVisibilityTrait::class,
                array_keys((new \ReflectionClass(get_class($adapter)))->getTraits())
            )) {
                $this->assertEquals('public', $adapter->getVisibility('destination.txt')['visibility']);
            }
        });
    }

    /**
     * @test
     */
    public function reading_a_file_that_does_not_exist(): void
    {
        $this->runScenario(function () {
            $content = $this->adapter()->read('path.txt');

            $this->assertFalse($content);
        });
    }

    /**
     * @test
     */
    public function moving_a_file_that_does_not_exist(): void
    {
        $this->runScenario(function () {
            $result = $this->adapter()->rename('source.txt', 'destination.txt');

            $this->assertFalse($result);
        });
    }

    /**
     * @test
     */
    public function trying_to_delete_a_non_existing_file(): void
    {
        $adapter = $this->adapter();

        $adapter->delete('path.txt');
        $fileExists = $adapter->has('path.txt');

        $this->assertFalse($fileExists);
    }

    /**
     * @test
     */
    public function checking_if_files_exist(): void
    {
        $adapter = $this->adapter();

        $fileExistsBefore = $adapter->has('some/path.txt');
        $adapter->write('some/path.txt', 'contents', new Config());
        $fileExistsAfter = $adapter->has('some/path.txt');

        $this->assertFalse($fileExistsBefore);
        $this->assertNotFalse($fileExistsAfter);
    }

    /**
     * @test
     */
    public function fetching_last_modified(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write('path.txt', 'contents', new Config());

            $attributes = $adapter->getTimestamp('path.txt');

            $this->assertNotFalse($attributes);
            $this->assertIsArray($attributes);
            $this->assertIsInt($attributes['timestamp']);
            $this->assertTrue($attributes['timestamp'] > time() - 30);
            $this->assertTrue($attributes['timestamp'] < time() + 30);
        });
    }

    /**
     * @test
     */
    public function failing_to_read_a_non_existing_file_into_a_stream(): void
    {
        $result = $this->adapter()->readStream('something.txt');
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function failing_to_read_a_non_existing_file(): void
    {
        $result = $this->adapter()->readStream('something.txt');
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function creating_a_directory(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $adapter->createDir('path', new Config());

            // Creating a directory should be idempotent.
            $adapter->createDir('path', new Config());

            $contents = $adapter->listContents('', false);
            $this->assertCount(1, $contents, $this->formatIncorrectListingCount($contents));
            $directory = $contents[0];
            $this->assertIsArray($directory);
            $this->assertEquals('path', $directory['path']);
        });
    }

    /**
     * @test
     */
    public function copying_a_file_with_collision(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write('path.txt', 'new contents', new Config());
            $adapter->write('new-path.txt', 'contents', new Config());

            $adapter->copy('path.txt', 'new-path.txt');
            $contents = $adapter->read('new-path.txt');

            $this->assertEquals('new contents', $contents['contents']);
        });
    }

    protected function assertFileExistsAtPath(string $path): void
    {
        $this->runScenario(function () use ($path) {
            $fileExists = $this->adapter()->has($path);
            $this->assertTrue($fileExists);
        });
    }
}
