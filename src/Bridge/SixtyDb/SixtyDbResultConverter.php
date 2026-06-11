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

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * Maps raw 60db HTTP responses to Symfony AI typed results:
 *
 *   60db-tiny chat → TextResult     (choices[0].message.content)
 *   tts-synthesize → BinaryResult   (mp3/wav/ogg bytes)
 *   stt            → TextResult     ('text' field)
 */
final class SixtyDbResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return \in_array($model->getName(), ['60db-tiny', 'tts-synthesize', 'tts-stream', 'stt', '60db-stt-v01'], true);
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $data = $result->getData();

        // TTS: SixtyDbModelClient's sync path pre-decodes the audio and
        // attaches it as `audio` + `mime`. Streaming path is signaled by
        // absence of `audio` — caller is expected to consume the raw
        // NDJSON stream via the deferred result's raw access.
        if (isset($data['audio']) && \is_string($data['audio'])) {
            return new BinaryResult($data['audio'], $data['mime'] ?? 'audio/mpeg');
        }

        // STT: flat `text` field.
        if (isset($data['text']) && !isset($data['choices'])) {
            return new TextResult((string) $data['text']);
        }

        // Chat completion: OpenAI-style choices array.
        if (isset($data['choices'][0]['message']['content'])) {
            return new TextResult((string) $data['choices'][0]['message']['content']);
        }

        // Fallback — surface raw JSON as text so callers can inspect.
        return new TextResult(json_encode($data, \JSON_UNESCAPED_UNICODE) ?: '');
    }
}
