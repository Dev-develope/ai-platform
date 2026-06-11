<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\SixtyDb;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client that maps Symfony AI model invocations to 60db's REST surface:
 *
 *   60db-tiny  → POST /v1/chat/completions   (OpenAI-compatible)
 *   tts-synthesize / tts-stream → POST /tts-synthesize or /tts-stream
 *   stt → POST /stt                   (multipart upload)
 *
 * Auth: Authorization: Bearer <apiKey>
 * References:
 *   https://docs.60db.ai/api-reference/llm/chat-completion
 *   https://docs.60db.ai/api-reference/tts/text-to-speech
 *   https://docs.60db.ai/api-reference/tts/text-to-speech-stream
 *   https://docs.60db.ai/api-reference/stt/speech-to-text
 */
final class SixtyDbModelClient implements ModelClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $apiBase = 'https://api.60db.ai',
    ) {
        if ('' === $this->apiKey) {
            throw new InvalidArgumentException('60db API key must not be empty.');
        }
    }

    public function supports(Model $model): bool
    {
        return \in_array($model->getName(), ['60db-tiny', 'tts-synthesize', 'tts-stream', 'stt', '60db-stt-v01'], true);
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        return match ($model->getName()) {
            'tts-synthesize'       => $this->requestTts($payload, $options, 'sync'),
            'tts-stream'           => $this->requestTts($payload, $options, 'stream'),
            'stt', '60db-stt-v01'  => $this->requestStt($payload, $options),
            default                => $this->requestChat($payload, $options),
        };
    }

    /**
     * Chat completions — payload is an OpenAI-style $messages array (or
     * already-built request body when caller passes the full envelope).
     */
    private function requestChat(array|string $payload, array $options): RawHttpResult
    {
        $body = \is_array($payload) && isset($payload['messages'])
            ? $payload
            : ['messages' => \is_array($payload) ? $payload : [['role' => 'user', 'content' => (string) $payload]]];

        $body += [
            'model' => $options['model'] ?? '60db-tiny',
            'stream' => $options['stream'] ?? false,
            'temperature' => $options['temperature'] ?? 0.7,
            'top_k' => $options['top_k'] ?? 20,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        $response = $this->httpClient->request('POST', $this->apiBase . '/v1/chat/completions', [
            'headers' => $this->authHeaders(['Content-Type' => 'application/json']),
            'json' => $body,
        ]);
        return new RawHttpResult($response);
    }

    /**
     * TTS — surface chosen via options['transport']:
     *   'sync'   (default) → POST /tts-synthesize, returns audio bytes
     *   'stream'           → POST /tts-stream, returns RawHttpResult so the
     *                        caller can iterate NDJSON via getDataStream().
     */
    private function requestTts(array|string $payload, array $options, string $transport = 'sync'): RawResultInterface
    {
        $text = \is_string($payload) ? $payload : (string) ($payload['text'] ?? '');
        $body = [
            'text' => $text,
            'voice_id' => $options['voice_id'] ?? 'fbb75ed2-975a-40c7-9e06-38e30524a9a1',
            'enhance' => $options['enhance'] ?? true,
            'speed' => $options['speed'] ?? 1,
            'stability' => $options['stability'] ?? 50,
            'similarity' => $options['similarity'] ?? 75,
            'output_format' => $options['output_format'] ?? 'mp3',
        ];

        if ('stream' === $transport) {
            $response = $this->httpClient->request('POST', $this->apiBase . '/tts-stream', [
                'headers' => $this->authHeaders(['Content-Type' => 'application/json']),
                'json' => $body,
            ]);
            return new RawHttpResult($response);
        }

        // Sync: decode the base64 immediately so callers get bytes
        // ready for a BinaryResult downstream.
        $response = $this->httpClient->request('POST', $this->apiBase . '/tts-synthesize', [
            'headers' => $this->authHeaders(['Content-Type' => 'application/json']),
            'json' => $body,
        ]);
        $data = $response->toArray(false);
        $audio = isset($data['audio_base64']) ? base64_decode($data['audio_base64'], true) : '';
        // Pre-decoded audio handed to the converter via the data array
        // so SixtyDbResultConverter::convert() can wrap it in a BinaryResult.
        return new InMemoryRawResult(['audio' => $audio, 'mime' => 'audio/mpeg'] + $data);
    }

    /**
     * STT — multipart upload. Payload is either a file path (string) or
     * an array {'file' => stream|string, 'filename' => string}.
     */
    private function requestStt(array|string $payload, array $options): RawHttpResult
    {
        $filename = 'audio.wav';
        $fileResource = null;

        if (\is_string($payload)) {
            // File path on disk.
            $fileResource = fopen($payload, 'r');
            $filename = basename($payload);
        } elseif (isset($payload['file'])) {
            $fileResource = $payload['file'];
            $filename = $payload['filename'] ?? $filename;
        }
        if (null === $fileResource) {
            throw new InvalidArgumentException('60db STT: payload must be a file path or an array with "file".');
        }

        // Multipart form for /stt — Symfony HttpClient maps the array body
        // into multipart automatically when one value is a resource.
        // All optional fields below match the names documented in
        // https://docs.60db.ai/api-reference/stt/speech-to-text
        $multipart = ['file' => $fileResource];
        foreach ([
            'language', 'languages', 'keywords', 'context',
            'min_speakers', 'max_speakers', 'return_timestamps',
        ] as $strField) {
            if (!empty($options[$strField])) {
                $multipart[$strField] = (string) $options[$strField];
            }
        }
        foreach (['diarize', 'include_confidence', 'script_correction'] as $boolField) {
            if (\array_key_exists($boolField, $options)) {
                $multipart[$boolField] = $options[$boolField] ? 'true' : 'false';
            }
        }

        $response = $this->httpClient->request('POST', $this->apiBase . '/stt', [
            'headers' => $this->authHeaders(),
            'extra' => ['filename' => $filename],
            'body' => $multipart,
        ]);
        return new RawHttpResult($response);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function authHeaders(array $extra = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey] + $extra;
    }
}
