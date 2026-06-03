<?php

namespace App\Http\Controllers\V2\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BaseController extends Controller
{
    protected function success($data = null, string $message = 'ok', int $code = 0, int $status = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $appCode, int $status = 400, $data = null): JsonResponse
    {
        return response()->json([
            'code' => $appCode,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function legacy(callable $callback, string $message = 'ok', callable $transform = null): JsonResponse
    {
        try {
            $response = $callback();
            return $this->fromLegacyResponse($response, $message, $transform);
        } catch (HttpResponseException $e) {
            return $this->fromLegacyResponse($e->getResponse(), 'error');
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode() ?: 500;
            $appCode = $status === 401 || $status === 403 ? 40101 : ($status === 404 ? 40401 : 50001);
            $msg = $e->getMessage() ?: 'Request failed';
            return $this->error($msg, $appCode, $status);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: 'Server error', 50001, 500);
        }
    }

    protected function fromLegacyResponse($response, string $message = 'ok', callable $transform = null): JsonResponse
    {
        if ($response instanceof JsonResponse) {
            $status = $response->getStatusCode();
            $payload = $response->getData(true);
            if ($status >= 400) {
                $errorMessage = is_array($payload) && isset($payload['message'])
                    ? (string) $payload['message']
                    : $message;
                return $this->error($errorMessage ?: 'Request failed', $status === 404 ? 40401 : 50001, $status, $payload);
            }
            if ($transform) {
                $payload = $transform($payload);
            }
            $payload = $this->normalizeLegacyPayload($payload);

            if ($this->isWrappedPayload($payload)) {
                return response()->json($payload, $status);
            }

            return $this->success($payload, $message, 0, $status);
        }

        if ($response instanceof HttpResponse) {
            $status = $response->getStatusCode();
            $content = $response->getContent();
            $decoded = null;
            if (is_string($content) && $content !== '') {
                $candidate = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $candidate;
                }
            }

            if (is_array($decoded)) {
                if ($status >= 400) {
                    $errorMessage = isset($decoded['message']) ? (string) $decoded['message'] : $message;
                    return $this->error($errorMessage ?: 'Request failed', $status === 404 ? 40401 : 50001, $status, $decoded);
                }

                if ($transform) {
                    $decoded = $transform($decoded);
                }
                $decoded = $this->normalizeLegacyPayload($decoded);

                if ($this->isWrappedPayload($decoded)) {
                    return response()->json($decoded, $status);
                }

                return $this->success($decoded, $message, 0, $status);
            }
        }

        return $this->success($response, $message);
    }

    protected function normalizeLegacyPayload($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }

        if ($this->isWrappedPayload($payload)) {
            $data = $this->normalizeLegacyPayload($payload['data'] ?? null);
            $data = $this->flattenNestedDataPayload($data);
            return [
                'code' => (int) ($payload['code'] ?? 0),
                'message' => (string) ($payload['message'] ?? 'ok'),
                'data' => $data,
            ];
        }

        if (array_key_exists('data', $payload) && count($payload) === 1) {
            return $this->normalizeLegacyPayload($payload['data']);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizeLegacyPayload($value);
            }
        }

        return $this->flattenNestedDataPayload($payload);
    }

    protected function flattenNestedDataPayload($payload)
    {
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return $payload;
        }

        $nested = $payload['data'];
        unset($payload['data']);
        return array_merge($nested, $payload);
    }

    protected function isWrappedPayload($payload): bool
    {
        return is_array($payload)
            && array_key_exists('code', $payload)
            && array_key_exists('message', $payload)
            && array_key_exists('data', $payload);
    }
}
