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

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * Catalog of 60db models/endpoints — every key below is taken verbatim
 * from https://docs.60db.ai.
 *
 * LLM (real model names per /v1/chat/completions docs):
 *   '60db-tiny'      — fast small model, default in docs example
 *
 * TTS endpoints (60db /tts/models lists "60db Fast" + "60db Quality";
 * the wire calls below use the endpoint paths themselves as routing keys):
 *   'tts-synthesize' — POST /tts-synthesize (sync)
 *   'tts-stream'     — POST /tts-stream (NDJSON)
 *
 * STT (real model id per /stt/models docs):
 *   '60db-stt-v01'   — current STT model id; the legacy 'stt' alias is
 *                      kept for back-compat with the previous catalog.
 */
final class SixtyDbModelCatalog extends AbstractModelCatalog
{
    public function __construct()
    {
        $this->models = [
            // ---- LLM ----
            '60db-tiny' => [
                'class' => Model::class,
                'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STREAMING],
            ],
            // ---- TTS ----
            'tts-synthesize' => [
                'class' => Model::class,
                'capabilities' => [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::TEXT_TO_SPEECH],
            ],
            'tts-stream' => [
                'class' => Model::class,
                'capabilities' => [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::OUTPUT_STREAMING, Capability::TEXT_TO_SPEECH],
            ],
            // ---- STT ----
            // Real model id per https://docs.60db.ai/api-reference/models/get-stt-models
            '60db-stt-v01' => [
                'class' => Model::class,
                'capabilities' => [Capability::INPUT_AUDIO, Capability::OUTPUT_TEXT, Capability::SPEECH_TO_TEXT],
            ],
            // Endpoint-path alias for callers that want to route by path.
            'stt' => [
                'class' => Model::class,
                'capabilities' => [Capability::INPUT_AUDIO, Capability::OUTPUT_TEXT, Capability::SPEECH_TO_TEXT],
            ],
        ];
    }
}
