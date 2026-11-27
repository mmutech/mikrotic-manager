<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RouterHealthCheckResource;
use App\Http\Resources\RouterResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\VoucherResource;
use App\Models\User;
use App\Services\Api\ReportService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function overview(Request $request, ReportService $reports): JsonResponse
    {
        $user = $this->authorizeReports($request);

        return $this->json($reports->overview($user));
    }

    public function vouchers(Request $request, ReportService $reports): JsonResponse
    {
        $user = $this->authorizeReports($request);

        $data = $reports->voucherStats($user);
        $data['recent'] = VoucherResource::collection($data['recent'])->resolve($request);

        return $this->json($data);
    }

    public function revenue(Request $request, ReportService $reports): JsonResponse
    {
        $user = $this->authorizeReports($request);

        return $this->json($reports->revenue($user));
    }

    public function routers(Request $request, ReportService $reports): JsonResponse
    {
        $user = $this->authorizeReports($request);

        $data = $reports->routers($user);
        $data['routers'] = RouterResource::collection($data['routers'])->resolve($request);
        $data['recent_checks'] = RouterHealthCheckResource::collection($data['recent_checks'])->resolve($request);

        return $this->json($data);
    }

    public function subscriptions(Request $request, ReportService $reports): JsonResponse
    {
        $user = $this->authorizeReports($request);

        $data = $reports->subscriptions($user);
        $data['recent'] = SubscriptionResource::collection($data['recent'])->resolve($request);

        return $this->json($data);
    }

    private function authorizeReports(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can(Permissions::REPORTS_VIEW), 403, 'You do not have permission to view reports.');

        return $user;
    }
}
