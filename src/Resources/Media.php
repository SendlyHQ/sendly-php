<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\MediaFile;
use Sendly\Exceptions\ValidationException;

/**
 * Media resource for uploading MMS attachments
 */
class Media
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Upload a media file for MMS
     *
     * @param string $filePath Path to the file to upload
     * @param string|null $contentType MIME type (auto-detected if null)
     * @return MediaFile The uploaded media file
     * @throws ValidationException If file path is invalid
     */
    public function upload(string $filePath, ?string $contentType = null): MediaFile
    {
        if (!file_exists($filePath)) {
            throw new ValidationException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new ValidationException("File is not readable: {$filePath}");
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
        ];

        if ($contentType !== null) {
            $multipart[0]['headers'] = ['Content-Type' => $contentType];
        }

        $response = $this->client->postMultipart('/media', $multipart);

        $data = $response['media'] ?? $response['data'] ?? $response;
        return new MediaFile($data);
    }
}
