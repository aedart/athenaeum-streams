<?php

namespace Aedart\Streams;

use Aedart\Contracts\MimeTypes\Detectable;
use Aedart\Contracts\Streams\FileStream as FileStreamInterface;
use Aedart\Contracts\Streams\Hashing\Hashable;
use Aedart\Contracts\Streams\Locks\Lockable;
use Aedart\Contracts\Streams\Stream as StreamInterface;
use Aedart\Contracts\Streams\Transactions\Transactions;
use Aedart\MimeTypes\Concerns\MimeTypeDetection;
use Aedart\MimeTypes\Exceptions\MimeTypeDetectionException;
use Aedart\Streams\Exceptions\CannotOpenStream;
use Aedart\Streams\Exceptions\StreamException;
use Throwable;

/**
 * File Stream
 *
 * @author Alin Eugen Deac <aedart@gmail.com>
 * @package Aedart\Streams
 */
class FileStream extends Stream implements
    FileStreamInterface,
    Hashable,
    Lockable,
    Transactions,
    Detectable
{
    use Concerns\Hashing;
    use Concerns\Locking;
    use Concerns\Transactions;
    use Concerns\Copying;
    use Concerns\Wrapping;
    use MimeTypeDetection;

    /**
     * @inheritDoc
     */
    public static function open(string $filename, string $mode, bool $useIncludePath = false, $context = null): static
    {
        try {
            $stream = fopen($filename, $mode, $useIncludePath, $context);
        } catch (Throwable $e) {
            throw new CannotOpenStream($e->getMessage(), $e->getCode(), $e);
        }

        if ($stream === false) {
            throw new CannotOpenStream(sprintf('Stream could not be opened for %s (mode %s)', $filename, $mode));
        }

        return static::make($stream);
    }

    /**
     * @inheritDoc
     */
    public static function openMemory(string $mode = 'r+b', $context = null): static
    {
        return static::open('php://memory', $mode, false, $context);
    }

    /**
     * @inheritDoc
     */
    public static function openTemporary(string $mode = 'r+b', int|null $maximumMemory = null, $context = null): static
    {
        $filename = isset($maximumMemory)
            ? "php://temp/maxmemory:$maximumMemory"
            : 'php://temp';

        return static::open($filename, $mode, false, $context);
    }

    /**
     * @inheritDoc
     */
    public function copy(int|null $length = null, int $offset = 0): static
    {
        return $this->copyTo(null, $length, $offset);
    }

    /**
     * @inheritDoc
     */
    public function copyTo(StreamInterface|null $target = null, int|null $length = null, int $offset = 0): static
    {
        $target = $target ?? static::openTemporary();

        $this->performCopy($this, $target, $length, $offset);

        return $target;
    }

    /**
     * @inheritdoc
     */
    public function append(
        $data,
        int|null $length = null,
        int $offset = 0,
        int|null $maximumMemory = null
    ): static {
        $this
            ->positionToEnd()
            ->performCopy(
                $this->wrap($data, $maximumMemory),
                $this,
                $length,
                $offset
            );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function truncate(int $size, bool $moveToEnd = true): static
    {
        $this->assertNotDetached('Unable to truncate stream');

        if (ftruncate($this->resource(), $size) === false) {
            throw new StreamException(sprintf('Failed truncating stream to %d bytes', $size));
        }

        if ($moveToEnd) {
            return $this->positionToEnd();
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * **CAUTION**: _Method is only supported from PHP v8.1_
     * TODO: @see https://github.com/aedart/athenaeum/issues/105
     */
//    public function sync(bool $includeMeta = true): static
//    {
//        $this->assertNotDetached('Unable to synchronizes data to file');
//
//        if ($includeMeta) {
//            $result = fsync($this->resource());
//        } else {
//            $result = fdatasync($this->resource());
//        }
//
//        if ($result === false) {
//            throw new StreamException('Failed to synchronize data to file. Please check if stream is block or otherwise invalid');
//        }
//
//        return $this;
//    }

    /**
     * @inheritDoc
     */
    public function flush(): static
    {
        $this->assertNotDetached('Unable to flush output');

        if (fflush($this->resource()) === false) {
            throw new StreamException('Flush output failed. Please check if stream is block or otherwise invalid');
        }

        return $this;
    }

    /*****************************************************************
     * Internals
     ****************************************************************/

    /**
     * @inheritDoc
     */
    protected function mimeTypeData()
    {
        try {
            $this->assertNotDetached('Unable to obtain MIME-type data');

            return $this->resource();
        } catch (Throwable $e) {
            throw new MimeTypeDetectionException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
