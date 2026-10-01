<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;

class SubclassCompatTest extends TestCase
{
    private function declareSubclass(string $method): string
    {
        $autoload = var_export((string) realpath(__DIR__ . '/../vendor/autoload.php'), true);
        $script = tempnam(sys_get_temp_dir(), 'sendly-bc-');
        file_put_contents($script, "<?php\nrequire {$autoload};\nclass InstrumentedSendly extends \\Sendly\\Sendly\n{\n{$method}\n}\necho 'declared';\n");
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output);
        unlink($script);

        return implode("\n", $output);
    }

    public function testASubclassOverridingPostWithThe420SignatureStillLoads(): void
    {
        $this->assertSame('declared', $this->declareSubclass(
            'public function post(string $path, array $body = [], ?string $idempotencyKey = null, bool $autoIdempotencyKey = true): array'
            . ' { return parent::post($path, $body, $idempotencyKey, $autoIdempotencyKey); }'
        ));
    }

    public function testASubclassOverridingPostMultipartWithThe420SignatureStillLoads(): void
    {
        $this->assertSame('declared', $this->declareSubclass(
            'public function postMultipart(string $path, array $multipart, ?string $idempotencyKey = null): array'
            . ' { return parent::postMultipart($path, $multipart, $idempotencyKey); }'
        ));
    }
}
