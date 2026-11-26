<?php

namespace App\Jobs;

use App\Contracts\RouterGateway;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\RouterJob;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RestoreRouterConfigurationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $routerId,
        public readonly int $routerJobId,
        public readonly string $fileName,
        public readonly ?string $correlationId = null,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('router-management:'.$this->routerId))->expireAfter(190)->releaseAfter(10),
        ];
    }

    public function handle(RouterGateway $gateway, RouterJobService $jobs): void
    {
        $correlationId = app(CorrelationContext::class)->set($this->correlationId);
        $job = RouterJob::query()
            ->whereKey($this->routerJobId)
            ->where('router_id', $this->routerId)
            ->where('type', 'restore_configuration')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            Storage::disk('local')->delete($this->localPath());

            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for configuration restore.');
            Storage::disk('local')->delete($this->localPath());

            return;
        }

        $jobs->markRunning($job);
        $checks = [];

        try {
            $contents = Storage::disk('local')->get($this->localPath());

            if (! is_string($contents) || $contents === '') {
                throw new \RuntimeException('The uploaded .rsc file is no longer available for import.');
            }

            $checks['uploaded_file'] = [
                'status' => 'passed',
                'message' => sprintf('Read %s bytes from the uploaded .rsc file.', number_format(strlen($contents))),
            ];

            $dryRun = $gateway->importConfigurationFile($router, $this->fileName, true, $correlationId);
            $checks = [...$checks, ...$dryRun['checks']];
            $this->storeChecks($job, $checks);

            $import = $gateway->importConfigurationFile($router, $this->fileName, false, $correlationId);
            $checks = [...$checks, ...$import['checks']];
            $this->storeChecks($job, $checks);

            $probe = $gateway->probe($router, $correlationId);
            $checks['router_read_back'] = [
                'status' => 'passed',
                'message' => 'RouterOS identity '.$probe->identity.' was read back after the import.',
            ];
            $this->storeChecks($job, $checks);
        } catch (Throwable $exception) {
            $this->cleanup($gateway, $router, $correlationId);
            $checks['restore'] = [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
            $this->storeChecks($job, $checks);
            $category = $exception instanceof RouterProbeException
                ? $exception->category->value
                : null;
            $jobs->markFailed($job, $exception, $category);

            return;
        }

        $this->cleanup($gateway, $router, $correlationId);
        $checks['temporary_files_removed'] = [
            'status' => 'passed',
            'message' => 'The uploaded script was removed from MMUTech and the router.',
        ];
        $this->storeChecks($job, $checks);
        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed(
                $job,
                $exception ?? 'Configuration restore failed.',
            );
        }
    }

    /**
     * @param  array<string, array{status: string, message: string}>  $checks
     */
    private function storeChecks(RouterJob $job, array $checks): void
    {
        $payload = $job->payload ?? [];
        $payload['verification'] = $checks;
        $payload['verification_at'] = now()->toIso8601String();
        $job->forceFill(['payload' => $payload])->save();
    }

    private function cleanup(RouterGateway $gateway, Router $router, string $correlationId): void
    {
        try {
            $gateway->deleteConfigurationFile($router, $this->fileName, $correlationId);
        } catch (Throwable) {
            // The tracked job still reports the restore outcome; cleanup must
            // never replace the original failure with a secondary one.
        }

        Storage::disk('local')->delete($this->localPath());
    }

    private function localPath(): string
    {
        return 'router-imports/'.$this->fileName;
    }
}
