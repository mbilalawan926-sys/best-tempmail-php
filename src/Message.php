<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A full message, body included.
 */
final class Message extends MessageSummary
{
    public function __construct(
        string $id,
        string $from,
        string $subject,
        ?\DateTimeImmutable $date = null,
        bool $hasAttachments = false,
        public readonly string $to = '',
        public readonly string $text = '',
        public readonly string $html = '',
        /** @var list<Attachment> */
        public readonly array $attachments = [],
    ) {
        parent::__construct($id, $from, $subject, $date, $hasAttachments);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $attachments = [];
        foreach (is_array($data['attachments'] ?? null) ? $data['attachments'] : [] as $raw) {
            if (is_array($raw)) {
                $attachments[] = Attachment::fromArray($raw);
            }
        }

        return new static(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            from: is_string($data['from'] ?? null) ? $data['from'] : '',
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            date: Timestamps::parse($data['date'] ?? null),
            hasAttachments: (bool) ($data['has_attachments'] ?? ($attachments !== [])),
            to: is_string($data['to'] ?? null) ? $data['to'] : '',
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            html: is_string($data['html'] ?? null) ? $data['html'] : '',
            attachments: $attachments,
        );
    }

    /** Find an attachment by filename, or null if there is no such file. */
    public function attachment(string $filename): ?Attachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->filename === $filename) {
                return $attachment;
            }
        }

        return null;
    }
}
