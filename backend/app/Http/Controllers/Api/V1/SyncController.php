<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\BatchSyncRequest;
use App\Http\Resources\V1\SyncBatchResource;
use App\Jobs\ProcessAssessmentImageJob;
use App\Models\AssessmentImage;
use App\Models\PersonalAccessToken;
use App\Models\SyncBatch;
use App\Models\User;
use App\Services\Sync\FailureCode;
use App\Services\Sync\ImageBinaryStore;
use App\Services\Sync\ItemRejectedException;
use App\Services\Sync\SyncBatchService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SyncController extends Controller
{
    /**
     * POST /api/v1/sync/batch
     *
     * 201 for a newly ingested batch, 200 for a replay of a known batch.
     */
    public function storeBatch(BatchSyncRequest $request, SyncBatchService $sync): JsonResponse
    {
        $user = $this->user($request);
        $token = $user->currentAccessToken();

        $submission = $sync->submit(
            user: $user,
            token: $token instanceof PersonalAccessToken ? $token : null,
            payload: $request->validated(),
            rawBody: $request->getContent(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $batch = $submission->batch->refresh();

        return SyncBatchResource::make($batch)
            ->replayed($submission->replayed)
            ->response()
            ->setStatusCode($submission->replayed ? Response::HTTP_OK : Response::HTTP_CREATED)
            ->header('Idempotent-Replayed', $submission->replayed ? 'true' : 'false');
    }

    /**
     * GET /api/v1/sync/batches/{syncBatch}
     *
     * Devices may read their own batches; audit:read grants the organization's.
     */
    public function showBatch(Request $request, SyncBatch $syncBatch): JsonResponse
    {
        $user = $this->user($request);

        if ($syncBatch->user_id !== $user->id && ! $user->hasAbility(TokenAbility::AuditRead)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return SyncBatchResource::make($syncBatch)->response();
    }

    /**
     * PUT /api/v1/sync/images/{clientUuid}/content
     *
     * Raw image bytes as the request body. Idempotent: re-sending the content
     * of an image that is already stored returns 200 without rewriting it.
     */
    public function uploadImageContent(Request $request, string $clientUuid, ImageBinaryStore $binaries): JsonResponse
    {
        $image = AssessmentImage::query()->where('client_uuid', strtolower($clientUuid))->firstOrFail();

        if ($image->sync_status->hasStoredFile()) {
            return $this->imageResponse($image, Response::HTTP_OK);
        }

        $limit = (int) config('rebuild.media.max_image_bytes');
        $declaredLength = (int) $request->header('Content-Length', '0');

        if ($declaredLength > $limit) {
            return response()->json([
                'message' => "Image exceeds the {$limit}-byte limit.",
                'code' => FailureCode::ImageSizeMismatch->value,
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $bytes = $request->getContent();

        if ($bytes === '') {
            return response()->json([
                'message' => 'The request body must contain the image bytes.',
                'code' => FailureCode::InvalidImageContent->value,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $path = $binaries->store($image, $bytes);
        } catch (ItemRejectedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->failureCode->value,
                'retryable' => $exception->failureCode->isRetryable(),
            ], $exception->failureCode === FailureCode::StorageUnavailable
                ? Response::HTTP_SERVICE_UNAVAILABLE
                : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $image->markUploaded($path, strlen($bytes), (string) $image->mime_type);
        } catch (Throwable $exception) {
            $binaries->delete($image->disk, $path);

            throw $exception;
        }

        ProcessAssessmentImageJob::dispatch($image->id);

        return $this->imageResponse($image->refresh(), Response::HTTP_CREATED);
    }

    private function imageResponse(AssessmentImage $image, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $image->id,
                'client_uuid' => $image->client_uuid,
                'sync_status' => $image->sync_status->value,
                'uploaded_at' => $image->uploaded_at?->toIso8601String(),
                'processed_at' => $image->processed_at?->toIso8601String(),
            ],
        ], $status);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
