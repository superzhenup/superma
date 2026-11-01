<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__, 2) . '/error_handler.php';
require_once dirname(__DIR__, 2) . '/RequestBody.php';

final class CandidateApiRequestException extends InvalidArgumentException
{
}

final class CandidateApiScopeException extends RuntimeException
{
}

final class CandidateApiResponseBudgetException extends RuntimeException
{
}

final class CandidateApiConflictException extends RuntimeException
{
}

/**
 * Small, shared request/response boundary for the Candidate approval surface.
 *
 * The router authenticates and applies CSRF policy. This class adds the
 * endpoint-specific contract: bounded object JSON, exact fields, scalar type
 * checks, read-only query validation, and a hard response-byte ceiling.
 */
final class CandidateApiBoundary
{
    public const MAX_REQUEST_BYTES = 8192;
    public const MAX_PREVIEW_CANDIDATES = 20;
    public const MAX_PREVIEW_BYTES = 2 * 1024 * 1024;
    public const MAX_APPROVAL_ROWS = 40;

    /** @param list<string> $allowedFields @return array<string,mixed> */
    public static function readPostObject(string $expectedRoute, array $allowedFields): array
    {
        self::requireMethod('POST');
        $raw = readRequestBodyBounded(self::MAX_REQUEST_BYTES);
        if ($raw === null) {
            throw new CandidateApiRequestException('请求体超过 8 KiB 安全限制');
        }
        try {
            $input = $raw === ''
                ? []
                : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CandidateApiRequestException('请求 JSON 无效', 0, $exception);
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new CandidateApiRequestException('请求 JSON 必须是对象');
        }
        self::assertExactFields($input, array_values(array_unique([
            'route',
            ...$allowedFields,
        ])));
        self::assertRoute($input['route'] ?? null, $expectedRoute);
        return $input;
    }

    /** @param list<string> $allowedFields */
    public static function assertGetQuery(string $expectedRoute, array $allowedFields): void
    {
        self::requireMethod('GET');
        self::assertExactFields($_GET, array_values(array_unique([
            'route',
            ...$allowedFields,
        ])));
        self::assertRoute($_GET['route'] ?? null, $expectedRoute);
    }

    /** @param array<mixed> $input @param list<string> $allowedFields */
    public static function assertExactFields(array $input, array $allowedFields): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, $allowedFields, true)) {
                throw new CandidateApiRequestException('请求包含未知字段');
            }
        }
    }

    public static function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value)
            && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $parsed = is_int($filtered) ? $filtered : 0;
        } else {
            $parsed = 0;
        }
        if ($parsed <= 0) {
            throw new CandidateApiRequestException("{$field} 必须是正整数");
        }
        return $parsed;
    }

    /** @param array<string,mixed> $input */
    public static function requiredString(
        array $input,
        string $field,
        int $maxBytes,
        ?string $pattern = null
    ): string {
        $value = $input[$field] ?? null;
        if (!is_string($value)) {
            throw new CandidateApiRequestException("{$field} 必须是字符串");
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes
            || ($pattern !== null && preg_match($pattern, $value) !== 1)) {
            throw new CandidateApiRequestException("{$field} 格式无效");
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    public static function optionalText(array $input, string $field, int $maxBytes): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }
        if (!is_string($input[$field]) || preg_match('//u', $input[$field]) !== 1) {
            throw new CandidateApiRequestException("{$field} 必须是 UTF-8 字符串或 null");
        }
        $value = trim($input[$field]);
        if (strlen($value) > $maxBytes) {
            throw new CandidateApiRequestException("{$field} 超过长度限制");
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $payload */
    public static function encodePreviewResponse(array $payload): string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new CandidateApiResponseBudgetException(
                '候选稿响应无法安全编码',
                0,
                $exception
            );
        }
        if (strlen($json) > self::MAX_PREVIEW_BYTES) {
            throw new CandidateApiResponseBudgetException('候选稿预览超过响应安全限制');
        }
        return $json;
    }

    public static function emitError(Throwable $exception): void
    {
        if ($exception instanceof CandidateApiRequestException) {
            http_response_code(400);
            $payload = [
                'ok' => false,
                'code' => 'invalid_request',
                'msg' => $exception->getMessage(),
            ];
        } elseif ($exception instanceof CandidateUiDisabledException) {
            http_response_code(403);
            $payload = [
                'ok' => false,
                'code' => 'candidate_ui_disabled',
                'msg' => '候选稿审批功能尚未对这部小说开放',
            ];
        } elseif ($exception instanceof CandidateApiScopeException) {
            http_response_code(404);
            $payload = [
                'ok' => false,
                'code' => 'not_found',
                'msg' => '候选稿不存在或无权访问',
            ];
        } elseif ($exception instanceof CandidateApiResponseBudgetException) {
            http_response_code(413);
            $payload = safe_api_error_payload(
                $exception,
                '待处理候选稿过多或过大，无法在安全预算内预览',
                'candidate_preview_too_large'
            );
        } elseif ($exception instanceof CandidateApiConflictException) {
            http_response_code(409);
            $payload = safe_api_error_payload(
                $exception,
                '候选稿或章节状态已变化，请刷新后重试',
                'stale_or_conflict'
            );
        } elseif (is_a($exception, 'HarnessIdempotencyConflictException')) {
            http_response_code(409);
            $payload = safe_api_error_payload(
                $exception,
                '重复请求与原审批内容不一致，请刷新后重试',
                'idempotency_conflict'
            );
        } elseif (is_a($exception, 'HarnessStateTransitionException')
            || is_a($exception, 'ChapterCanonTransitionException')) {
            http_response_code(409);
            $payload = safe_api_error_payload(
                $exception,
                '候选稿或章节状态已变化，请刷新后重试',
                'stale_or_conflict'
            );
        } else {
            http_response_code(500);
            $payload = safe_api_error_payload(
                $exception,
                '候选稿操作失败，请稍后重试',
                'internal_error'
            );
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function requireMethod(string $expected): void
    {
        $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($actual !== $expected) {
            throw new CandidateApiRequestException("必须使用 {$expected}");
        }
    }

    private static function assertRoute(mixed $route, string $expected): void
    {
        if ($route !== null && (!is_string($route) || !hash_equals($expected, $route))) {
            throw new CandidateApiRequestException('路由字段与当前端点不一致');
        }
    }

    private function __construct()
    {
    }
}
