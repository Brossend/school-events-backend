<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class IdempotencyService
{
    /**
     * Check or initiate an idempotent request.
     *
     * Returns:
     * - ['replayed' => true, 'response' => ['code' => int, 'body' => array]] if completed previously
     * - ['replayed' => false, 'record' => IdempotencyKey] if new execution should proceed
     *
     * @throws ConflictHttpException
     */
    public function checkOrAcquire(User $user, string $endpoint, string $key, array $payload): array
    {
        $truncatedKey = substr(trim($key), 0, 128);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return \Illuminate\Support\Facades\DB::transaction(function () use ($user, $endpoint, $truncatedKey, $payloadHash) {
            $existing = IdempotencyKey::where('user_id', $user->id)
                ->where('endpoint', $endpoint)
                ->where('key', $truncatedKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->expires_at && $existing->expires_at->isPast()) {
                    $existing->delete();
                } else {
                    if ($existing->payload_sha256 !== $payloadHash) {
                        throw new ConflictHttpException(json_encode([
                            'error' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                            'message' => 'Idempotency key was previously used with different request parameters.',
                        ], JSON_UNESCAPED_UNICODE));
                    }

                    if ($existing->status === 'processing') {
                        if (! empty($existing->response_body)) {
                            return [
                                'replayed' => true,
                                'code' => $existing->response_code ?? 202,
                                'body' => $existing->response_body,
                            ];
                        }

                        $cachedRunId = $existing->response_body['run_id'] ?? null;
                        throw new ConflictHttpException(json_encode([
                            'error' => 'GENERATION_RUN_IN_PROGRESS',
                            'message' => 'A generation task with this idempotency key is already running or queued.',
                            'run_id' => $cachedRunId,
                        ], JSON_UNESCAPED_UNICODE));
                    }

                    if (in_array($existing->status, ['completed', 'failed']) && $existing->response_body !== null) {
                        return [
                            'replayed' => true,
                            'code' => $existing->response_code ?? ($existing->status === 'completed' ? 202 : 422),
                            'body' => $existing->response_body,
                        ];
                    }
                }
            }

            // Wrap insert in SAVEPOINT so that if concurrent insert causes unique violation,
            // PostgreSQL transaction remains active and can query the existing row!
            \Illuminate\Support\Facades\DB::statement('SAVEPOINT sp_idem_insert');

            try {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'endpoint' => $endpoint,
                    'key' => $truncatedKey,
                    'payload_sha256' => $payloadHash,
                    'status' => 'processing',
                    'expires_at' => now()->addHours(24),
                ]);

                \Illuminate\Support\Facades\DB::statement('RELEASE SAVEPOINT sp_idem_insert');

                return [
                    'replayed' => false,
                    'record' => $record,
                ];
            } catch (QueryException $e) {
                \Illuminate\Support\Facades\DB::statement('ROLLBACK TO SAVEPOINT sp_idem_insert');

                $retry = IdempotencyKey::where('user_id', $user->id)
                    ->where('endpoint', $endpoint)
                    ->where('key', $truncatedKey)
                    ->lockForUpdate()
                    ->first();

                if ($retry) {
                    if ($retry->payload_sha256 !== $payloadHash) {
                        throw new ConflictHttpException(json_encode([
                            'error' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                            'message' => 'Idempotency key was previously used with different request parameters.',
                        ], JSON_UNESCAPED_UNICODE));
                    }

                    if (! empty($retry->response_body)) {
                        return [
                            'replayed' => true,
                            'code' => $retry->response_code ?? ($retry->status === 'failed' ? 422 : 202),
                            'body' => $retry->response_body,
                        ];
                    }

                    if ($retry->status === 'processing') {
                        $cachedRunId = $retry->response_body['run_id'] ?? null;
                        throw new ConflictHttpException(json_encode([
                            'error' => 'GENERATION_RUN_IN_PROGRESS',
                            'message' => 'A generation task with this idempotency key is already running or queued.',
                            'run_id' => $cachedRunId,
                        ], JSON_UNESCAPED_UNICODE));
                    }
                }

                throw $e;
            }
        });
    }

    /**
     * Attach full response payload early to processing key so replays return immediately.
     */
    public function attachResponsePayload(IdempotencyKey $record, array $body, int $code = 202): void
    {
        $record->update([
            'response_code' => $code,
            'response_body' => $body,
        ]);
    }

    /**
     * Attach run_id early to processing key so conflict responses know the active run.
     */
    public function attachRunId(IdempotencyKey $record, string $runId): void
    {
        $record->update([
            'response_body' => ['run_id' => $runId],
        ]);
    }

    /**
     * Mark idempotency key as completed with response payload.
     */
    public function markCompleted(IdempotencyKey $record, int $code, array $body): void
    {
        $record->update([
            'status' => 'completed',
            'response_code' => $code,
            'response_body' => $body,
        ]);
    }

    /**
     * Mark idempotency key as failed.
     */
    public function markFailed(IdempotencyKey $record, int $code, array $body): void
    {
        $record->update([
            'status' => 'failed',
            'response_code' => $code,
            'response_body' => $body,
        ]);
    }
}
