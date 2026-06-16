<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\AppDomainService;
use App\Services\SubscribeMonitorService;
use Illuminate\Http\Request;

class SubscribeMonitorController extends Controller
{
    public function fetch(Request $request)
    {
        $filters = $request->only(['days', 'limit', 'page', 'per_page', 'keyword', 'type', 'disposition_keyword', 'operator', 'watch_overdue_days']);

        return response([
            'data' => (new SubscribeMonitorService())->fetch($filters)
        ]);
    }

    public function config()
    {
        return response([
            'data' => (new SubscribeMonitorService())->getRiskRules()
        ]);
    }

    public function saveConfig(Request $request)
    {
        if (!(new SubscribeMonitorService())->saveRiskRules($request->input('rules', []))) {
            abort(500, '风险规则保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function rebuildSnapshots(Request $request)
    {
        return response([
            'data' => (new SubscribeMonitorService())->rebuildSnapshots($request->only(['days', 'limit', 'page', 'per_page', 'keyword', 'type', 'disposition_keyword', 'operator', 'watch_overdue_days']))
        ]);
    }

    public function disposition(Request $request)
    {
        try {
            $operator = $request->user();
        } catch (\Throwable $e) {
            $operator = null;
        }
        $data = (new SubscribeMonitorService())->saveDisposition($request->all(), $operator);

        return response([
            'data' => $data
        ]);
    }

    public function dispositionLogs(Request $request)
    {
        $userId = (int) $request->input('user_id', 0);

        return response([
            'data' => (new SubscribeMonitorService())->getDispositionLogs($userId)
        ]);
    }

    public function dispatchPreview(Request $request)
    {
        $userId = (int) $request->input('user_id', 0);

        return response([
            'data' => (new AppDomainService())->previewDispatchForUserId($userId, 20)
        ]);
    }
}
