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

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only helper for the 60db voice + model discovery endpoints.
 *
 * Endpoints (taken verbatim from https://docs.60db.ai):
 *   GET /default-voices  — paginated/public list of system voices
 *   GET /my-voices       — voices in the caller's account
 *   GET /tts/models      — available TTS models ('60db Fast' / '60db Quality')
 *   GET /stt/models      — available STT models (incl. id '60db-stt-v01')
 *
 * Auth: Authorization: Bearer <apiKey> (same scheme used by ModelClient).
 *
 * Voice response objects include: voice_id, name, category, model, labels
 * (language, language_name, gender, accent), description, is_native,
 * available_for_tiers, categories.
 */
final class SixtyDbVoices
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $apiBase = 'https://api.60db.ai',
    ) {
    }

    /**
     * Lists the default (public/system) 60db voices.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDefaultVoices(): array
    {
        return $this->fetch('/default-voices');
    }

    /**
     * Lists voices in the caller's account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMyVoices(): array
    {
        return $this->fetch('/my-voices');
    }

    /**
     * Lists available TTS models (e.g. "60db Fast", "60db Quality").
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTtsModels(): array
    {
        return $this->fetch('/tts/models');
    }

    /**
     * Lists available STT models (returns objects with `id` field — current
     * docs example id is "60db-stt-v01").
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSttModels(): array
    {
        return $this->fetch('/stt/models');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $path): array
    {
        $response = $this->httpClient->request('GET', $this->apiBase . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
        ]);
        $body = $response->toArray(false);
        // 60db responses wrap the array under a `data` key per the docs.
        return \is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
