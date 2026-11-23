<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use JsonException;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Data\RegisterExchangeJobInput;
use Vaulter\EditorPro\Exchange\Enums\ExchangeFormat;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;

/** Request validator for `POST /documents/{node}/exchange-jobs`. */
#[Internal]
final class RegisterExchangeJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_key' => ['nullable', 'string', 'min:1', 'max:255'],
            'kind' => ['required', 'string', 'in:import,export'],
            'format' => ['nullable', 'string', 'in:docx'],
            'profile_id' => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:64'],
            'source_blob_id' => ['nullable', 'string', 'max:191'],
            'source_revision_id' => ['nullable', 'string', 'max:191'],
            'source_generation' => ['nullable', 'integer', 'min:0'],
            'source_position' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'array'],
            'source.blob_id' => ['nullable', 'string', 'max:191'],
            'source.node_id' => ['nullable', 'string', 'max:191'],
            'source.revision_id' => ['nullable', 'string', 'max:191'],
            'source.generation' => ['nullable', 'integer', 'min:0'],
            'source.position' => ['nullable', 'integer', 'min:0'],
            'docx_b64' => ['nullable', 'string'],
            'file' => ['nullable', 'file'],
            'request_fingerprint' => ['nullable', 'string', 'size:64', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'options' => ['nullable'],
        ];
    }

    public function toData(): RegisterExchangeJobInput
    {
        $requestKey = $this->string('request_key')->trim()->toString();
        if ($requestKey === '') {
            $requestKey = trim($this->headers->get('Idempotency-Key') ?? '');
        }
        if ($requestKey === '') {
            throw ExchangeException::invalidCommand('request_key is required.');
        }

        $kind = ExchangeJobKind::from($this->string('kind')->toString());
        $formatStr = $this->string('format')->trim()->toString();
        $format = ExchangeFormat::from($formatStr !== '' ? strtolower($formatStr) : 'docx');

        $profileId = $this->string('profile_id')->trim()->toString();
        if ($profileId === '') {
            $profileId = $this->string('profile')->trim()->toString();
        }

        /** @var array<string, mixed> $source */
        $source = is_array($this->input('source')) ? $this->input('source') : [];

        $sourceBlobId = self::optStr($this->input('source_blob_id') ?? $source['blob_id'] ?? null);
        $sourceNodeId = self::optStr($source['node_id'] ?? null);
        $sourceRevisionId = self::optStr($this->input('source_revision_id') ?? $source['revision_id'] ?? null);
        $sourceGeneration = self::optInt($this->input('source_generation') ?? $source['generation'] ?? null);
        $sourcePosition = self::optInt($this->input('source_position') ?? $source['position'] ?? null);

        $rawBytes = null;
        $uploaded = $this->file('file');
        if ($uploaded instanceof UploadedFile) {
            $contents = file_get_contents($uploaded->getRealPath());
            $rawBytes = is_string($contents) ? $contents : null;
        } elseif (is_string($b64 = $this->input('docx_b64')) && $b64 !== '') {
            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                throw ExchangeException::invalidCommand('Invalid base64 payload in docx_b64.');
            }
            $rawBytes = $decoded;
        }

        $rawOpts = $this->input('options');
        if (is_string($rawOpts) && trim($rawOpts) !== '') {
            try {
                $decodedOpts = json_decode($rawOpts, true, 64, JSON_THROW_ON_ERROR);
                $rawOpts = is_array($decodedOpts) ? $decodedOpts : [];
            } catch (JsonException) {
                throw ExchangeException::invalidCommand('options must be valid JSON object.');
            }
        }
        /** @var array<string, mixed> $options */
        $options = is_array($rawOpts) ? self::stringKeyMap($rawOpts) : [];

        return new RegisterExchangeJobInput(
            requestKey: $requestKey,
            kind: $kind,
            format: $format,
            profileId: $profileId !== '' ? $profileId : null,
            sourceBlobId: $sourceBlobId,
            sourceNodeId: $sourceNodeId,
            sourceRevisionId: $sourceRevisionId,
            sourceGeneration: $sourceGeneration,
            sourcePosition: $sourcePosition,
            rawBytes: $rawBytes,
            requestFingerprintHex: self::optStr($this->input('request_fingerprint')),
            options: $options,
        );
    }

    private static function optStr(mixed $val): ?string
    {
        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }

    private static function optInt(mixed $val): ?int
    {
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && ctype_digit($val)) {
            return (int) $val;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
