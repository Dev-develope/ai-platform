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

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * 60db cloud bridge — registers TTS, STT, and Chat models with the
 * Symfony AI Platform.
 *
 * Usage:
 *   $provider = new SixtyDbProvider($httpClient, $apiKey);
 *   $platform = new Platform([$provider]);
 *   $platform->invoke('60db-tiny', 'Hello!');
 *   $platform->invoke('tts-synthesize', 'Read this aloud');
 *   $platform->invoke('stt', '/path/to/audio.mp3');
 *
 * Reference: https://docs.60db.ai
 */
final class SixtyDbProvider implements ProviderInterface
{
    private readonly SixtyDbModelClient $client;
    private readonly SixtyDbResultConverter $converter;
    private readonly SixtyDbModelCatalog $catalog;
    private readonly SixtyDbVoices $voices;

    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $apiKey,
        string $apiBase = 'https://api.60db.ai',
    ) {
        $this->client = new SixtyDbModelClient($httpClient, $apiKey, $apiBase);
        $this->converter = new SixtyDbResultConverter();
        $this->catalog = new SixtyDbModelCatalog();
        $this->voices = new SixtyDbVoices($httpClient, $apiKey, $apiBase);
    }

    /**
     * Discovery helper — list default voices, the caller's voices, and
     * available TTS/STT models via the documented 60db discovery endpoints.
     *
     * Example:
     *   foreach ($provider->voices()->listDefaultVoices() as $v) {
     *       echo $v['voice_id'].' '.$v['name'].\PHP_EOL;
     *   }
     */
    public function voices(): SixtyDbVoices
    {
        return $this->voices;
    }

    public function getName(): string
    {
        return '60db';
    }

    public function supports(string $modelName): bool
    {
        return \in_array($modelName, ['60db-tiny', 'tts-synthesize', 'tts-stream', 'stt', '60db-stt-v01'], true);
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        $modelObj = $this->catalog->getModel($model);

        // ProviderInterface accepts object inputs too; collapse to the
        // shapes SixtyDbModelClient understands (array | string).
        $payload = \is_object($input) ? (array) $input : $input;

        return new DeferredResult(
            $this->converter,
            $modelObj,
            $this->client->request($modelObj, $payload, $options),
            $options,
        );
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->catalog;
    }
}
