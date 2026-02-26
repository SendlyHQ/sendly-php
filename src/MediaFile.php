<?php

declare(strict_types=1);

namespace Sendly;

/**
 * Represents an uploaded media file
 */
class MediaFile
{
    public readonly string $id;
    public readonly string $url;
    public readonly string $contentType;
    public readonly int $sizeBytes;

    /**
     * Create a MediaFile from API response data
     *
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->url = $data['url'] ?? '';
        $this->contentType = $data['content_type'] ?? $data['contentType'] ?? '';
        $this->sizeBytes = (int) ($data['size_bytes'] ?? $data['sizeBytes'] ?? 0);
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'content_type' => $this->contentType,
            'size_bytes' => $this->sizeBytes,
        ];
    }
}
