<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A downloaded attachment: the bytes, plus what the server said they were.
 */
final class AttachmentFile
{
    public function __construct(
        public readonly string $contents,
        /** From the Content-Disposition header, or empty if the server sent none. */
        public readonly string $filename,
        /** From the Content-Type header. */
        public readonly string $mime,
    ) {
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * Write the bytes to a path.
     *
     * @return int bytes written
     */
    public function saveTo(string $path): int
    {
        $written = file_put_contents($path, $this->contents);
        if ($written === false) {
            throw new TransportException(sprintf('could not write the attachment to %s', $path));
        }

        return $written;
    }
}
