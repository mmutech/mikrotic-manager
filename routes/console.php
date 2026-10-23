<?php

use App\Jobs\SyncRouterHealthJob;
use App\Models\ApiLog;
use App\Models\RouterHealthCheck;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SyncRouterHealthJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('model:prune', ['--model' => [ApiLog::class, RouterHealthCheck::class]])
    ->dailyAt('02:15')
    ->onOneServer();
