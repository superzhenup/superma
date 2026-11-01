<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Test transport contract used below AIClient's payload/protocol layer.
 *
 * Implementations are deliberately absent from production. A fake can only be
 * installed through HarnessRuntime::installForTesting(). Requests never carry
 * API keys or authorization headers.
 */
interface HarnessAiFake
{
    /** @return array{status_code:int,body:string} */
    public function complete(array $request): array;

    /**
     * Events are decoded provider stream events consumed by AIClient's normal
     * OpenAI/Anthropic normalization path.
     *
     * @return array{status_code:int,events:array<int,array>,error?:string}
     */
    public function stream(array $request): array;
}
