<?php

namespace App\Services;

use App\Models\SubscribeAccessLog;
use App\Models\SubscribeDisposition;
use App\Models\SubscribeDispositionLog;
use App\Models\SubscribeIpCache;
use App\Models\SubscribeRiskSnapshot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SubscribeMonitorService
{
    protected const TABLE = 'v2_subscribe_access_logs';
    protected const DISPOSITION_TABLE = 'v2_subscribe_dispositions';
    protected const DISPOSITION_LOG_TABLE = 'v2_subscribe_disposition_logs';
    protected const SNAPSHOT_TABLE = 'v2_subscribe_risk_snapshots';
    protected static $tableReady;
    protected static $dispositionTableReady;
    protected static $dispositionLogTableReady;
    protected static $snapshotTableReady;

    public const DISPOSITION_STATUSES = [
        'none',
        'watch',
        'handled',
        'whitelist',
        'freeze_suggested',
        'blacklist_suggested',
    ];

    public const RISK_LEVELS = [
        '无风险',
        '中风险',
        '高风险',
        '极危险',
    ];

    public function record(Request $request, $user = null, string $type = 'client_subscribe', array $context = []): void
    {
        try {
            if (!$this->tableExists()) {
                return;
            }

            $token = $this->getUserValue($user, 'token') ?: (string) $request->input('token', '');
            [$ip, $source] = $this->resolveIp($request);
            $trafficUsed = (int) $this->getUserValue($user, 'u') + (int) $this->getUserValue($user, 'd');
            $trafficTotal = (int) $this->getUserValue($user, 'transfer_enable');

            SubscribeAccessLog::create([
                'user_id' => $this->nullableInt($this->getUserValue($user, 'id')),
                'email' => $this->limitString($this->getUserValue($user, 'email'), 255),
                'token_hash' => $token !== '' ? hash('sha256', $token) : null,
                'subscribe_type' => $this->limitString($type, 64) ?: 'client_subscribe',
                'flag' => $this->limitString($context['flag'] ?? $request->input('flag', ''), 128),
                'request_host' => $this->limitString($request->getHttpHost(), 255),
                'request_path' => $this->limitString('/' . ltrim($request->path(), '/'), 255),
                'client_ip' => $this->limitString($ip, 64),
                'real_ip_source' => $this->limitString($source, 32),
                'user_agent' => $this->limitString($request->userAgent() ?? '', 1000),
                'plan_id' => $this->nullableInt($this->getUserValue($user, 'plan_id')),
                'traffic_used' => max(0, $trafficUsed),
                'traffic_total' => max(0, $trafficTotal),
                'expired_at' => $this->nullableInt($this->getUserValue($user, 'expired_at')),
                'status' => (int) ($context['status'] ?? 1),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // 行为监管只做观察，任何写入失败都不能影响订阅下发。
        }
    }

    public function fetch(array $filters = []): array
    {
        if (!$this->tableExists()) {
            return [
                'installed' => false,
                'summary' => $this->emptySummary(),
                'host_stats' => [],
                'high_frequency' => [],
                'token_multi_ip' => [],
                'ip_multi_token' => [],
                'high_risk_profiles' => [],
                'watch_profiles' => [],
                'blacklist_profiles' => [],
                'profile_overview' => [
                    'source' => 'none',
                    'global' => false,
                    'snapshot_enabled' => $this->snapshotTableExists(),
                    'message' => '行为监管数据表不存在。',
                ],
                'page_risk_summary' => $this->emptyRiskSummary(),
                'user_profiles' => [],
                'recent' => [],
                'snapshot_status' => $this->snapshotStatus(),
                'ip_intelligence_status' => $this->ipIntelligenceStatus(),
                'retention_policy' => $this->retentionPolicy(),
                'risk_rules' => $this->getRiskRules(),
                'filters' => $this->normalizeFilters($filters),
                'disposition_filter_options' => $this->dispositionFilterOptions(),
            ];
        }

        $filters = $this->normalizeFilters($filters);
        $base = $this->filteredQuery($filters);
        $userProfiles = $this->userProfiles($base, false, $filters);
        $pagination = $this->paginationMeta($base, $filters);
        $profileOverview = $this->profileOverview($base, $filters, $userProfiles);

        return [
            'installed' => true,
            'summary' => $this->summary($base, $filters),
            'risk_summary' => $profileOverview['risk_summary'],
            'page_risk_summary' => $this->riskSummary($userProfiles),
            'host_stats' => $this->hostStats($base),
            'high_frequency' => $this->highFrequency($base),
            'token_multi_ip' => $this->tokenMultiIp($base),
            'ip_multi_token' => $this->ipMultiToken($base),
            'high_risk_profiles' => $profileOverview['high_risk_profiles'],
            'watch_profiles' => $profileOverview['watch_profiles'],
            'blacklist_profiles' => $profileOverview['blacklist_profiles'],
            'profile_overview' => $profileOverview['meta'],
            'user_profiles' => $userProfiles,
            'recent' => $this->recent($base, $filters['limit']),
            'snapshot_status' => $this->snapshotStatus(),
            'ip_intelligence_status' => $this->ipIntelligenceStatus(),
            'retention_policy' => $this->retentionPolicy(),
            'risk_rules' => $this->getRiskRules(),
            'filters' => $filters,
            'pagination' => $pagination,
            'disposition_filter_options' => $this->dispositionFilterOptions(),
        ];
    }

    public function getRiskRules(): array
    {
        $saved = [];
        $configPath = base_path('/config/v2board.php');
        if (is_file($configPath)) {
            try {
                $config = include $configPath;
                if (is_array($config)) {
                    $saved = $config['subscribe_monitor_risk_rules'] ?? [];
                }
            } catch (\Throwable $e) {
                $saved = config('v2board.subscribe_monitor_risk_rules', []);
            }
        } else {
            $saved = config('v2board.subscribe_monitor_risk_rules', []);
        }
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_replace_recursive($this->defaultRiskRules(), $saved);
    }

    public function saveRiskRules(array $data): bool
    {
        $rules = $this->normalizeRiskRules($data);
        $config = config('v2board', []);
        $config['subscribe_monitor_risk_rules'] = $rules;
        $configPath = base_path('/config/v2board.php');
        $contents = var_export($config, true);
        if (!File::put($configPath, "<?php\n\nreturn {$contents};\n")) {
            return false;
        }
        config(['v2board' => $config]);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }
        try {
            \Illuminate\Support\Facades\Artisan::call('config:clear');
        } catch (\Throwable $e) {
        }
        return true;
    }

    public function rebuildSnapshots(array $filters = []): array
    {
        if (!$this->tableExists()) {
            return ['rebuilt' => 0, 'stored' => 0, 'reason' => 'access log table missing'];
        }
        if (!$this->snapshotTableExists()) {
            return ['rebuilt' => 0, 'stored' => 0, 'reason' => 'snapshot table missing'];
        }

        $filters = $this->normalizeFilters($filters);
        $base = $this->filteredQuery($filters);
        $profiles = $this->userProfiles($base, true, $filters);
        $stored = 0;
        foreach ($profiles as $profile) {
            if (!empty($profile['snapshot']['stored'])) {
                $stored++;
            }
        }

        return [
            'rebuilt' => count($profiles),
            'stored' => $stored,
            'snapshot_status' => $this->snapshotStatus(),
        ];
    }

    public function saveDisposition(array $data, $operator = null): array
    {
        if (!$this->dispositionTableExists() || !$this->dispositionLogTableExists()) {
            abort(500, '处置记录表不存在，请先执行迁移脚本');
        }

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            abort(500, '用户ID不能为空');
        }
        $status = $this->normalizeDispositionStatus($data['status'] ?? 'watch');
        $note = $this->limitString($data['note'] ?? '', 1000);
        $riskLevel = $this->normalizeRiskLevel($data['risk_level'] ?? null);
        $riskScore = isset($data['risk_score']) ? max(0, min(100, (int) $data['risk_score'])) : null;
        $now = time();
        $user = class_exists(User::class) ? User::find($userId) : null;
        $email = $this->limitString($data['email'] ?? ($user->email ?? ''), 255);
        $operatorId = $this->nullableInt($this->getUserValue($operator, 'id'));
        $operatorEmail = $this->limitString($this->getUserValue($operator, 'email'), 255);
        $existing = SubscribeDisposition::where('user_id', $userId)->first();
        $fromStatus = $existing ? $existing->status : null;

        if ($status === 'none') {
            if ($existing) {
                $existing->delete();
            }
        } else {
            $payload = [
                'user_id' => $userId,
                'email' => $email,
                'status' => $status,
                'level' => $riskLevel,
                'note' => $note,
                'operator_id' => $operatorId,
                'operator_email' => $operatorEmail,
                'handled_at' => in_array($status, ['handled', 'whitelist'], true) ? $now : null,
                'expires_at' => $this->nullableInt($data['expires_at'] ?? null),
                'updated_at' => $now,
            ];
            if ($existing) {
                $existing->update($payload);
            } else {
                $payload['created_at'] = $now;
                SubscribeDisposition::create($payload);
            }
        }

        SubscribeDispositionLog::create([
            'user_id' => $userId,
            'email' => $email,
            'action' => $status === 'none' ? 'clear' : $status,
            'from_status' => $fromStatus,
            'to_status' => $status === 'none' ? null : $status,
            'risk_level' => $riskLevel,
            'risk_score' => $riskScore,
            'note' => $note,
            'operator_id' => $operatorId,
            'operator_email' => $operatorEmail,
            'created_at' => $now,
        ]);

        return $this->getDispositionForUser($userId);
    }

    public function clearUserProfile(int $userId, $operator = null, string $note = ''): array
    {
        if ($userId <= 0) {
            abort(500, '用户ID不能为空');
        }

        $user = class_exists(User::class) ? User::find($userId) : null;
        $email = $this->limitString($user->email ?? '', 255);
        $operatorId = $this->nullableInt($this->getUserValue($operator, 'id'));
        $operatorEmail = $this->limitString($this->getUserValue($operator, 'email'), 255);
        $note = $this->limitString($note ?: '人工确认后清除行为画像', 1000);
        $now = time();
        $deleted = [
            'access_logs' => 0,
            'risk_snapshots' => 0,
            'disposition' => 0,
        ];

        DB::transaction(function () use ($userId, $email, $operatorId, $operatorEmail, $note, $now, &$deleted) {
            if ($this->tableExists()) {
                $deleted['access_logs'] = SubscribeAccessLog::where('user_id', $userId)->delete();
            }
            if ($this->snapshotTableExists()) {
                $deleted['risk_snapshots'] = SubscribeRiskSnapshot::where('user_id', $userId)->delete();
            }
            if ($this->dispositionTableExists()) {
                $deleted['disposition'] = SubscribeDisposition::where('user_id', $userId)->delete();
            }
            if ($this->dispositionLogTableExists()) {
                SubscribeDispositionLog::create([
                    'user_id' => $userId,
                    'email' => $email,
                    'action' => 'clear_profile',
                    'from_status' => null,
                    'to_status' => null,
                    'risk_level' => null,
                    'risk_score' => null,
                    'note' => $note,
                    'operator_id' => $operatorId,
                    'operator_email' => $operatorEmail,
                    'created_at' => $now,
                ]);
            }
        });

        return [
            'user_id' => $userId,
            'email' => $email,
            'deleted' => $deleted,
            'disposition' => $this->emptyDisposition(),
        ];
    }

    public function getDispositionForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->dispositionTableExists()) {
            return $this->emptyDisposition();
        }

        $row = SubscribeDisposition::where('user_id', $userId)->first();
        if (!$row) {
            return $this->emptyDisposition();
        }

        return $this->dispositionRowToArray($row);
    }

    public function getDispositionLogs(int $userId, int $limit = 20): array
    {
        if ($userId <= 0 || !$this->dispositionLogTableExists()) {
            return [];
        }

        return SubscribeDispositionLog::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->user_id,
                    'email' => $row->email,
                    'action' => $row->action,
                    'from_status' => $row->from_status,
                    'to_status' => $row->to_status,
                    'risk_level' => $row->risk_level,
                    'risk_score' => $row->risk_score !== null ? (int) $row->risk_score : null,
                    'note' => $row->note,
                    'operator_id' => $row->operator_id !== null ? (int) $row->operator_id : null,
                    'operator_email' => $row->operator_email,
                    'created_at' => (int) $row->created_at,
                ];
            })
            ->toArray();
    }

    public function riskSnapshotForUser($user, int $days = 7): array
    {
        if (!$user || !$this->tableExists()) {
            return [
                'risk_level' => '无风险',
                'risk_score' => 0,
                'disposition' => $this->emptyDisposition(),
            ];
        }

        $filters = $this->normalizeFilters(['days' => $days, 'limit' => 10]);
        $base = $this->filteredQuery($filters)->where('user_id', (int) ($user->id ?? 0));
        $row = (clone $base)
            ->select(
                'user_id',
                'email',
                DB::raw('MAX(plan_id) as plan_id'),
                DB::raw('MAX(traffic_used) as traffic_used'),
                DB::raw('MAX(traffic_total) as traffic_total'),
                DB::raw('MAX(expired_at) as expired_at'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT client_ip) as ips'),
                DB::raw('COUNT(DISTINCT request_host) as hosts'),
                DB::raw('COUNT(DISTINCT user_agent) as agents'),
                DB::raw('COUNT(DISTINCT subscribe_type) as types'),
                DB::raw('COUNT(DISTINCT token_hash) as tokens'),
                DB::raw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as failures'),
                DB::raw('MIN(created_at) as first_seen'),
                DB::raw('MAX(created_at) as last_seen')
            )
            ->groupBy('user_id', 'email')
            ->first();

        if (!$row) {
            return [
                'risk_level' => '无风险',
                'risk_score' => 0,
                'disposition' => $this->getDispositionForUser((int) ($user->id ?? 0)),
            ];
        }

        $rules = $this->getRiskRules();
        $metrics = $this->userBehaviorMetrics($base, $row, $rules);
        $risk = $this->riskProfile($row, $metrics, $rules);
        $disposition = $this->getDispositionForUser((int) $row->user_id);
        if (($disposition['status'] ?? 'none') === 'whitelist') {
            $risk['original_level'] = $risk['level'];
            $risk['original_score'] = $risk['score'];
            $risk['level'] = '无风险';
            $risk['score'] = 0;
        }

        return [
            'risk_level' => $risk['level'],
            'risk_score' => (int) $risk['score'],
            'original_risk_level' => $risk['original_level'] ?? $risk['level'],
            'original_risk_score' => (int) ($risk['original_score'] ?? $risk['score']),
            'disposition' => $disposition,
        ];
    }

    protected function summary($base, array $filters): array
    {
        $todayStart = strtotime(date('Y-m-d 00:00:00'));

        return [
            'total' => (int) (clone $base)->count(),
            'today' => (int) (clone $base)->where('created_at', '>=', $todayStart)->count(),
            'unique_users' => (int) (clone $base)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'unique_ips' => (int) (clone $base)->whereNotNull('client_ip')->distinct('client_ip')->count('client_ip'),
            'unique_tokens' => (int) (clone $base)->whereNotNull('token_hash')->distinct('token_hash')->count('token_hash'),
            'range_days' => $filters['days'],
        ];
    }

    protected function hostStats($base): array
    {
        return (clone $base)
            ->select('request_host', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT user_id) as users'), DB::raw('COUNT(DISTINCT client_ip) as ips'))
            ->whereNotNull('request_host')
            ->groupBy('request_host')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'request_host' => $row->request_host,
                    'total' => (int) $row->total,
                    'users' => (int) $row->users,
                    'ips' => (int) $row->ips,
                ];
            })
            ->toArray();
    }

    protected function highFrequency($base): array
    {
        return (clone $base)
            ->select('user_id', 'email', 'token_hash', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT client_ip) as ips'), DB::raw('MAX(created_at) as last_seen'))
            ->whereNotNull('token_hash')
            ->groupBy('user_id', 'email', 'token_hash')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'user_id' => (int) $row->user_id,
                    'email' => $row->email,
                    'token_hash' => $this->shortHash($row->token_hash),
                    'total' => (int) $row->total,
                    'ips' => (int) $row->ips,
                    'last_seen' => (int) $row->last_seen,
                ];
            })
            ->toArray();
    }

    protected function tokenMultiIp($base): array
    {
        return (clone $base)
            ->select('user_id', 'email', 'token_hash', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT client_ip) as ips'), DB::raw('MAX(created_at) as last_seen'))
            ->whereNotNull('token_hash')
            ->groupBy('user_id', 'email', 'token_hash')
            ->havingRaw('COUNT(DISTINCT client_ip) >= 2')
            ->orderByDesc('ips')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'user_id' => (int) $row->user_id,
                    'email' => $row->email,
                    'token_hash' => $this->shortHash($row->token_hash),
                    'total' => (int) $row->total,
                    'ips' => (int) $row->ips,
                    'last_seen' => (int) $row->last_seen,
                ];
            })
            ->toArray();
    }

    protected function ipMultiToken($base): array
    {
        return (clone $base)
            ->select('client_ip', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT token_hash) as tokens'), DB::raw('COUNT(DISTINCT user_id) as users'), DB::raw('MAX(created_at) as last_seen'))
            ->whereNotNull('client_ip')
            ->groupBy('client_ip')
            ->havingRaw('COUNT(DISTINCT token_hash) >= 2')
            ->orderByDesc('tokens')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'client_ip' => $row->client_ip,
                    'total' => (int) $row->total,
                    'tokens' => (int) $row->tokens,
                    'users' => (int) $row->users,
                    'last_seen' => (int) $row->last_seen,
                ];
            })
            ->toArray();
    }

    protected function profileOverview($base, array $filters, array $pageProfiles): array
    {
        $fallback = [
            'risk_summary' => $this->riskSummary($pageProfiles),
            'high_risk_profiles' => $this->profileList($pageProfiles, ['高风险', '极危险']),
            'watch_profiles' => $this->watchProfileList($pageProfiles),
            'blacklist_profiles' => $this->dispositionProfileList($pageProfiles, ['blacklist_suggested']),
            'meta' => [
                'source' => 'page',
                'global' => false,
                'snapshot_enabled' => $this->snapshotTableExists(),
                'user_scope_count' => count($pageProfiles),
                'message' => '风险快照为空时临时使用当前页统计，请先点击重算风险快照。',
            ],
        ];

        if (!$this->snapshotTableExists()) {
            return $fallback;
        }

        $userIds = $this->filteredUserIds($base);
        if (!$userIds) {
            return [
                'risk_summary' => $this->emptyRiskSummary(),
                'high_risk_profiles' => [],
                'watch_profiles' => [],
                'blacklist_profiles' => [],
                'meta' => [
                    'source' => 'snapshot',
                    'global' => true,
                    'snapshot_enabled' => true,
                    'snapshot_rows' => 0,
                    'user_scope_count' => 0,
                    'message' => '当前筛选范围内暂无账号。',
                ],
            ];
        }

        $latestRows = $this->latestSnapshotRowsForUsers($userIds);
        if ($latestRows->isEmpty()) {
            $fallback['meta']['source'] = 'page_no_snapshot';
            $fallback['meta']['snapshot_enabled'] = true;
            $fallback['meta']['user_scope_count'] = count($userIds);
            return $fallback;
        }

        $profiles = [];
        foreach ($latestRows as $row) {
            $profiles[] = $this->snapshotRowToCompactProfile($row, $filters);
        }
        $profiles = $this->filterOverviewProfiles($profiles, $filters);

        return [
            'risk_summary' => $this->riskSummary($profiles),
            'high_risk_profiles' => $this->profileList($profiles, ['高风险', '极危险']),
            'watch_profiles' => $this->watchProfileList($profiles),
            'blacklist_profiles' => $this->dispositionProfileList($profiles, ['blacklist_suggested']),
            'meta' => [
                'source' => 'snapshot',
                'global' => true,
                'snapshot_enabled' => true,
                'snapshot_rows' => count($profiles),
                'user_scope_count' => count($userIds),
                'latest_snapshot_at' => (int) $latestRows->max('snapshot_at'),
                'message' => '顶部统计和处置队列来自当前筛选范围内的最新风险快照。',
            ],
        ];
    }

    protected function filteredUserIds($base): array
    {
        return array_values(array_unique(array_map('intval', (clone $base)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->toArray())));
    }

    protected function latestSnapshotRowsForUsers(array $userIds)
    {
        if (!$userIds || !$this->snapshotTableExists()) {
            return collect();
        }

        $latest = SubscribeRiskSnapshot::query()
            ->select('user_id', DB::raw('MAX(snapshot_at) as latest_snapshot_at'), DB::raw('MAX(id) as latest_id'))
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id');

        return SubscribeRiskSnapshot::query()
            ->joinSub($latest, 'latest_snapshots', function ($join) {
                $join->on('v2_subscribe_risk_snapshots.user_id', '=', 'latest_snapshots.user_id')
                    ->on('v2_subscribe_risk_snapshots.snapshot_at', '=', 'latest_snapshots.latest_snapshot_at');
            })
            ->select('v2_subscribe_risk_snapshots.*')
            ->orderByDesc('risk_score')
            ->orderByDesc('last_seen')
            ->get()
            ->unique('user_id')
            ->values();
    }

    protected function filterOverviewProfiles(array $profiles, array $filters): array
    {
        $risk = $filters['risk'] ?? '';
        $disposition = $filters['disposition'] ?? '';
        if ($risk === '' && $disposition === '') {
            return $profiles;
        }

        return array_values(array_filter($profiles, function ($profile) use ($risk, $disposition) {
            if ($risk !== '' && $this->riskKey($profile['risk_level'] ?? '无风险') !== $risk) {
                return false;
            }
            if ($disposition !== '' && (($profile['disposition']['status'] ?? 'none') !== $disposition)) {
                return false;
            }
            return true;
        }));
    }

    protected function snapshotRowToCompactProfile($row, array $filters): array
    {
        $disposition = [
            'status' => $row->disposition_status ?: 'none',
            'label' => $row->disposition_label ?: $this->dispositionLabel($row->disposition_status ?: 'none'),
            'level' => null,
            'note' => null,
            'operator_id' => null,
            'operator_email' => null,
            'handled_at' => null,
            'expires_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];

        if ($this->dispositionTableExists()) {
            $current = SubscribeDisposition::where('user_id', (int) $row->user_id)->first();
            if ($current) {
                $disposition = $this->dispositionRowToArray($current);
            }
        }

        return [
            'user_id' => (int) $row->user_id,
            'email' => $row->email ?: '',
            'plan_id' => 0,
            'risk_level' => $row->risk_level ?: '无风险',
            'risk_score' => (int) $row->risk_score,
            'original_risk_level' => $row->original_risk_level,
            'original_risk_score' => $row->original_risk_score !== null ? (int) $row->original_risk_score : null,
            'risk_explain' => $this->snapshotRiskExplain($row),
            'disposition' => $disposition,
            'disposition_overdue' => $this->dispositionOverdue($disposition, $filters),
            'total' => (int) $row->request_total,
            'ips' => (int) $row->ip_count,
            'hosts' => (int) $row->host_count,
            'agents' => (int) $row->agent_count,
            'traffic_used' => (int) $row->traffic_used,
            'traffic_total' => (int) $row->traffic_total,
            'first_seen' => $row->first_seen !== null ? (int) $row->first_seen : null,
            'last_seen' => $row->last_seen !== null ? (int) $row->last_seen : 0,
        ];
    }

    protected function profileList(array $profiles, array $levels): array
    {
        return array_values(array_slice(array_map(function ($profile) {
            return $this->compactProfile($profile);
        }, array_filter($profiles, function ($profile) use ($levels) {
            return in_array($profile['risk_level'] ?? '无风险', $levels, true);
        })), 0, 10));
    }

    protected function watchProfileList(array $profiles): array
    {
        $rules = $this->getRiskRules();
        $threshold = (int) ($rules['queue']['watch_score'] ?? $rules['levels']['critical'] ?? 80);
        $manual = array_filter($profiles, function ($profile) use ($threshold) {
            $status = $profile['disposition']['status'] ?? 'none';
            if ($status === 'watch') {
                return true;
            }
            if ($status !== 'none') {
                return false;
            }
            return ($profile['risk_level'] ?? '无风险') === '极危险'
                || (int) ($profile['risk_score'] ?? 0) >= $threshold;
        });

        return array_values(array_slice(array_map(function ($profile) {
            return $this->compactProfile($profile);
        }, $manual), 0, 10));
    }

    protected function dispositionProfileList(array $profiles, array $statuses): array
    {
        return array_values(array_slice(array_map(function ($profile) {
            return $this->compactProfile($profile);
        }, array_filter($profiles, function ($profile) use ($statuses) {
            return in_array($profile['disposition']['status'] ?? 'none', $statuses, true);
        })), 0, 12));
    }

    protected function compactProfile(array $profile): array
    {
        return [
            'user_id' => (int) ($profile['user_id'] ?? 0),
            'email' => $profile['email'] ?? '',
            'risk_level' => $profile['risk_level'] ?? '无风险',
            'risk_score' => (int) ($profile['risk_score'] ?? 0),
            'risk_explain' => $profile['risk_explain'] ?? [],
            'disposition' => $profile['disposition'] ?? $this->emptyDisposition(),
            'disposition_overdue' => $profile['disposition_overdue'] ?? $this->dispositionOverdue($profile['disposition'] ?? $this->emptyDisposition(), []),
            'plan_id' => (int) ($profile['plan_id'] ?? 0),
            'total' => (int) ($profile['total'] ?? 0),
            'ips' => (int) ($profile['ips'] ?? 0),
            'hosts' => (int) ($profile['hosts'] ?? 0),
            'last_seen' => (int) ($profile['last_seen'] ?? 0),
        ];
    }

    protected function userProfiles($base, bool $skipDispatchPreview = false, array $filters = []): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = (clone $base)
            ->select(
                'user_id',
                'email',
                DB::raw('MAX(plan_id) as plan_id'),
                DB::raw('MAX(traffic_used) as traffic_used'),
                DB::raw('MAX(traffic_total) as traffic_total'),
                DB::raw('MAX(expired_at) as expired_at'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT client_ip) as ips'),
                DB::raw('COUNT(DISTINCT request_host) as hosts'),
                DB::raw('COUNT(DISTINCT user_agent) as agents'),
                DB::raw('COUNT(DISTINCT subscribe_type) as types'),
                DB::raw('COUNT(DISTINCT token_hash) as tokens'),
                DB::raw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as failures'),
                DB::raw('MIN(created_at) as first_seen'),
                DB::raw('MAX(created_at) as last_seen')
            )
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'email')
            ->orderByDesc('last_seen')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $rules = $this->getRiskRules();

        $dispositions = $this->dispositionsForRows($rows, $filters);

        return $rows->map(function ($row) use ($base, $rules, $dispositions, $filters) {
            $detail = (clone $base)
                ->where('user_id', $row->user_id)
                ->orderByDesc('id')
                ->limit(12)
                ->get();
            $metrics = $this->userBehaviorMetrics($base, $row, $rules);
            $risk = $this->riskProfile($row, $metrics, $rules);
            $disposition = $dispositions[(int) $row->user_id] ?? $this->emptyDisposition();
            $originalRisk = $risk;
            if (($disposition['status'] ?? 'none') === 'whitelist') {
                $risk['level'] = '无风险';
                $risk['score'] = 0;
            }
            $riskExplain = $this->riskExplanation($risk, $metrics, $disposition);

            $profile = [
                'user_id' => (int) $row->user_id,
                'email' => $row->email,
                'plan_id' => (int) $row->plan_id,
                'traffic_used' => (int) $row->traffic_used,
                'traffic_total' => (int) $row->traffic_total,
                'expired_at' => (int) $row->expired_at,
                'total' => (int) $row->total,
                'ips' => (int) $row->ips,
                'hosts' => (int) $row->hosts,
                'agents' => (int) $row->agents,
                'types' => (int) $row->types,
                'tokens' => (int) $row->tokens,
                'failures' => (int) $row->failures,
                'first_seen' => (int) $row->first_seen,
                'last_seen' => (int) $row->last_seen,
                'risk_level' => $risk['level'],
                'risk_score' => $risk['score'],
                'original_risk_level' => $originalRisk['level'],
                'original_risk_score' => $originalRisk['score'],
                'risk_reasons' => $risk['reasons'],
                'risk_signals' => $risk['signals'],
                'risk_explain' => $riskExplain,
                'disposition' => $disposition,
                'disposition_overdue' => $this->dispositionOverdue($disposition, $filters),
                'disposition_logs' => $this->getDispositionLogs((int) $row->user_id, 10),
                'behavior' => $metrics,
                'recent' => $detail->map(function ($item) use ($metrics) {
                    $geo = $metrics['geo']['ip_map'][$item->client_ip] ?? null;
                    return [
                        'id' => (int) $item->id,
                        'subscribe_type' => $item->subscribe_type,
                        'flag' => $item->flag,
                        'request_host' => $item->request_host,
                        'request_path' => $item->request_path,
                        'client_ip' => $item->client_ip,
                        'real_ip_source' => $item->real_ip_source,
                        'ip_region' => $geo,
                        'user_agent' => $item->user_agent,
                        'status' => (int) $item->status,
                        'created_at' => (int) $item->created_at,
                    ];
                })->toArray(),
            ];
            $profile['snapshot'] = $this->recordRiskSnapshot($profile);
            $profile['risk_timeline'] = $this->riskTimelineForUser((int) $row->user_id, 12);
            return $profile;
        })->sort(function ($a, $b) {
            return ($b['risk_score'] <=> $a['risk_score']) ?: ($b['last_seen'] <=> $a['last_seen']);
        })->values()->toArray();
    }

    protected function riskSummary(array $profiles): array
    {
        $summary = [
            'safe' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];
        foreach ($profiles as $profile) {
            $level = $profile['risk_level'] ?? '无风险';
            if ($level === '极危险') {
                $summary['critical']++;
            } elseif ($level === '高风险') {
                $summary['high']++;
            } elseif ($level === '中风险') {
                $summary['medium']++;
            } else {
                $summary['safe']++;
            }
        }
        return $summary;
    }

    protected function riskExplanation(array $risk, array $metrics, array $disposition): array
    {
        $signals = $risk['signals'] ?? [];
        $positive = array_values(array_filter($signals, function ($signal) {
            return (int) ($signal['score'] ?? 0) > 0;
        }));
        $discounts = array_values(array_filter($signals, function ($signal) {
            return (int) ($signal['score'] ?? 0) < 0;
        }));
        usort($positive, function ($a, $b) {
            return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        });

        $traffic = $metrics['traffic'] ?? [];
        $usedDelta = (int) ($traffic['used_delta'] ?? 0);
        $pulls = (int) ($traffic['pulls'] ?? 0);
        $hasNoTrafficSignal = $this->signalsContain($signals, ['no_traffic_after_subscribe', 'low_traffic_after_subscribe']);
        $hasHardSignal = $this->signalsContain($signals, ['ip_proxy', 'ip_vpn', 'ip_tor', 'ip_bot', 'shared_ip_users', 'shared_ip_tokens', 'risk_host']);
        $level = $risk['level'] ?? '无风险';
        $status = $disposition['status'] ?? 'none';

        if ($status === 'whitelist') {
            $summary = '已加入白名单，当前按无风险处理。';
            $suggestion = '保持白名单，定期复核即可。';
        } elseif ($hasNoTrafficSignal) {
            $summary = '订阅拉取后流量增长很低，是当前最主要疑点。';
            $suggestion = $level === '极危险' ? '建议优先人工复核，确认后再拉黑或隔离入口。' : '建议加入观察区，持续看后续流量是否增长。';
        } elseif ($usedDelta > 0) {
            $summary = '账号有实际流量增长，短期频繁刷新更像排障或客户端重试。';
            $suggestion = $hasHardSignal ? '存在网络/IP异常，建议观察，不建议仅凭刷新频率拉黑。' : '建议继续观察，不建议直接拉黑。';
        } elseif ($pulls > 0 && $level !== '无风险') {
            $summary = '当前主要由 IP、入口、客户端变化触发，还缺少流量侧证据。';
            $suggestion = '建议先观察，等待更多拉取和流量样本。';
        } else {
            $summary = '当前没有明显异常行为。';
            $suggestion = '无需处理。';
        }

        return [
            'summary' => $summary,
            'suggestion' => $suggestion,
            'evidence' => array_values(array_map(function ($signal) {
                return [
                    'code' => $signal['code'] ?? '',
                    'label' => $signal['label'] ?? ($signal['code'] ?? ''),
                    'score' => (int) ($signal['score'] ?? 0),
                    'value' => $signal['value'] ?? null,
                ];
            }, array_slice($positive, 0, 5))),
            'discounts' => array_values(array_map(function ($signal) {
                return [
                    'code' => $signal['code'] ?? '',
                    'label' => $signal['label'] ?? ($signal['code'] ?? ''),
                    'score' => (int) ($signal['score'] ?? 0),
                    'value' => $signal['value'] ?? null,
                ];
            }, array_slice($discounts, 0, 5))),
        ];
    }

    protected function signalsContain(array $signals, array $codes): bool
    {
        foreach ($signals as $signal) {
            if (in_array($signal['code'] ?? '', $codes, true) && (int) ($signal['score'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    public function recordRiskSnapshot(array $profile): array
    {
        if (!$this->snapshotTableExists()) {
            return ['stored' => false, 'reason' => 'snapshot table missing'];
        }

        $userId = (int) ($profile['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['stored' => false, 'reason' => 'invalid user'];
        }

        $disposition = $profile['disposition'] ?? $this->emptyDisposition();
        $now = time();
        $payload = [
            'user_id' => $userId,
            'email' => $this->limitString($profile['email'] ?? '', 255),
            'risk_level' => $profile['risk_level'] ?? '无风险',
            'risk_score' => max(0, min(100, (int) ($profile['risk_score'] ?? 0))),
            'original_risk_level' => $profile['original_risk_level'] ?? ($profile['risk_level'] ?? '无风险'),
            'original_risk_score' => max(0, min(100, (int) ($profile['original_risk_score'] ?? ($profile['risk_score'] ?? 0)))),
            'disposition_status' => $disposition['status'] ?? 'none',
            'disposition_label' => $disposition['label'] ?? $this->dispositionLabel($disposition['status'] ?? 'none'),
            'signals' => $profile['risk_signals'] ?? [],
            'metrics' => $this->snapshotMetrics($profile),
            'request_total' => (int) ($profile['total'] ?? 0),
            'ip_count' => (int) ($profile['ips'] ?? 0),
            'host_count' => (int) ($profile['hosts'] ?? 0),
            'agent_count' => (int) ($profile['agents'] ?? 0),
            'traffic_used' => (int) ($profile['traffic_used'] ?? 0),
            'traffic_total' => (int) ($profile['traffic_total'] ?? 0),
            'first_seen' => $this->nullableInt($profile['first_seen'] ?? null),
            'last_seen' => $this->nullableInt($profile['last_seen'] ?? null),
            'snapshot_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $last = SubscribeRiskSnapshot::where('user_id', $userId)->orderByDesc('id')->first();
            if ($last && !$this->snapshotChanged($last, $payload)) {
                return [
                    'stored' => false,
                    'reason' => 'unchanged',
                    'last_snapshot_at' => (int) $last->snapshot_at,
                    'count' => $this->snapshotCountForUser($userId),
                ];
            }

            $payload['created_at'] = $now;
            $row = SubscribeRiskSnapshot::create($payload);
            return [
                'stored' => true,
                'id' => (int) $row->id,
                'snapshot_at' => (int) $row->snapshot_at,
                'count' => $this->snapshotCountForUser($userId),
            ];
        } catch (\Throwable $e) {
            return ['stored' => false, 'reason' => 'write failed'];
        }
    }

    protected function snapshotMetrics(array $profile): array
    {
        $behavior = $profile['behavior'] ?? [];
        return [
            'risk_explain' => $profile['risk_explain'] ?? [],
            'windows' => $behavior['windows'] ?? [],
            'traffic' => $behavior['traffic'] ?? [],
            'geo' => array_diff_key($behavior['geo'] ?? [], ['ip_map' => true]),
            'share' => $behavior['share'] ?? [],
            'host' => $behavior['host'] ?? [],
            'client' => $behavior['client'] ?? [],
        ];
    }

    protected function snapshotRiskExplain($row): array
    {
        $metrics = is_array($row->metrics) ? $row->metrics : (json_decode((string) $row->metrics, true) ?: []);
        $explain = $metrics['risk_explain'] ?? [];
        return is_array($explain) ? $explain : [];
    }

    protected function snapshotChanged($last, array $payload): bool
    {
        foreach (['risk_level', 'risk_score', 'original_risk_level', 'original_risk_score', 'disposition_status', 'request_total', 'ip_count', 'host_count', 'agent_count', 'traffic_used'] as $key) {
            if ((string) ($last->{$key} ?? '') !== (string) ($payload[$key] ?? '')) {
                return true;
            }
        }

        $lastSignals = is_array($last->signals) ? $last->signals : (json_decode((string) $last->signals, true) ?: []);
        return md5(json_encode($lastSignals)) !== md5(json_encode($payload['signals'] ?? []));
    }

    protected function snapshotCountForUser(int $userId): int
    {
        if ($userId <= 0 || !$this->snapshotTableExists()) {
            return 0;
        }
        return (int) SubscribeRiskSnapshot::where('user_id', $userId)->count();
    }

    protected function riskTimelineForUser(int $userId, int $limit = 12): array
    {
        if ($userId <= 0 || !$this->snapshotTableExists()) {
            return [];
        }

        return SubscribeRiskSnapshot::where('user_id', $userId)
            ->orderByDesc('snapshot_at')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(function ($row) {
                $signals = is_array($row->signals) ? $row->signals : (json_decode((string) $row->signals, true) ?: []);
                return [
                    'id' => (int) $row->id,
                    'risk_level' => $row->risk_level,
                    'risk_score' => (int) $row->risk_score,
                    'original_risk_level' => $row->original_risk_level,
                    'original_risk_score' => $row->original_risk_score !== null ? (int) $row->original_risk_score : null,
                    'disposition_status' => $row->disposition_status,
                    'disposition_label' => $row->disposition_label ?: $this->dispositionLabel($row->disposition_status ?: 'none'),
                    'signals' => array_values(array_slice($signals, 0, 6)),
                    'request_total' => (int) $row->request_total,
                    'ip_count' => (int) $row->ip_count,
                    'host_count' => (int) $row->host_count,
                    'traffic_used' => (int) $row->traffic_used,
                    'traffic_total' => (int) $row->traffic_total,
                    'first_seen' => $row->first_seen !== null ? (int) $row->first_seen : null,
                    'last_seen' => $row->last_seen !== null ? (int) $row->last_seen : null,
                    'snapshot_at' => (int) $row->snapshot_at,
                ];
            })
            ->toArray();
    }

    protected function snapshotStatus(): array
    {
        if (!$this->snapshotTableExists()) {
            return [
                'enabled' => false,
                'count' => 0,
                'users' => 0,
                'latest_at' => null,
            ];
        }

        return [
            'enabled' => true,
            'count' => (int) SubscribeRiskSnapshot::count(),
            'users' => (int) SubscribeRiskSnapshot::distinct('user_id')->count('user_id'),
            'latest_at' => (int) SubscribeRiskSnapshot::max('snapshot_at'),
            'retention_days' => $this->retentionPolicy()['risk_snapshot_days'],
        ];
    }

    protected function ipIntelligenceStatus(): array
    {
        if (!Schema::hasTable('v2_subscribe_ip_cache')) {
            return [
                'enabled' => false,
                'cache_count' => 0,
                'intelligence_count' => 0,
                'database_enabled' => (new Ip2RegionService())->databaseStatus()['enabled'] ?? false,
                'database_mtime' => null,
                'database_size' => 0,
            ];
        }

        $query = SubscribeIpCache::query();
        $intelligence = (clone $query)->where(function ($q) {
            $q->whereNotNull('asn')
                ->orWhereNotNull('as_name')
                ->orWhereNotNull('network_type')
                ->orWhereNotNull('ip_risk_type');
        });

        $database = (new Ip2RegionService())->databaseStatus();

        return [
            'enabled' => true,
            'database_enabled' => (bool) ($database['enabled'] ?? false),
            'database_mtime' => $database['mtime'] ?? null,
            'database_size' => $database['size'] ?? 0,
            'cache_count' => (int) (clone $query)->count(),
            'intelligence_count' => (int) $intelligence->count(),
            'asn_count' => (int) (clone $query)->whereNotNull('asn')->count(),
            'network_type_count' => (int) (clone $query)->whereNotNull('network_type')->count(),
            'idc_count' => (int) (clone $query)->where('network_type', 'idc')->count(),
            'mobile_count' => (int) (clone $query)->where('network_type', 'mobile')->count(),
            'fixed_count' => (int) (clone $query)->where('network_type', 'fixed')->count(),
            'risk_type_count' => (int) (clone $query)->whereNotNull('ip_risk_type')->count(),
            'vpn_count' => (int) (clone $query)->where('ip_risk_type', 'vpn')->count(),
            'proxy_count' => (int) (clone $query)->where('ip_risk_type', 'proxy')->count(),
            'tor_count' => (int) (clone $query)->where('ip_risk_type', 'tor')->count(),
            'bot_count' => (int) (clone $query)->where('ip_risk_type', 'bot')->count(),
            'miss_count' => (int) (clone $query)->where('hit', 0)->count(),
            'hit_count' => (int) (clone $query)->where('hit', 1)->count(),
            'latest_at' => (int) (clone $query)->max('updated_at'),
            'retention_days' => $this->retentionPolicy()['ip_cache_days'],
        ];
    }

    protected function retentionPolicy(): array
    {
        return [
            'access_log_days' => 180,
            'risk_snapshot_days' => 365,
            'ip_cache_days' => 90,
            'disposition_logs' => '长期保留',
        ];
    }

    protected function userBehaviorMetrics($base, $row, array $rules): array
    {
        $now = time();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $userQuery = (clone $base)->where('user_id', $row->user_id);
        $hosts = (clone $userQuery)->whereNotNull('request_host')->distinct()->pluck('request_host')->filter()->values()->toArray();
        $agents = (clone $userQuery)->distinct()->pluck('user_agent')->map(function ($value) {
            return trim((string) $value);
        })->values()->toArray();
        $ips = (clone $userQuery)->whereNotNull('client_ip')->distinct()->limit(80)->pluck('client_ip')->filter()->values()->toArray();
        $timestamps = (clone $userQuery)->orderByDesc('created_at')->limit(240)->pluck('created_at')->map(function ($value) {
            return (int) $value;
        })->toArray();

        $minuteRow = (clone $userQuery)
            ->select(DB::raw('FLOOR(created_at / 60) as minute_key'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('FLOOR(created_at / 60)'))
            ->orderByDesc('total')
            ->first();

        $shared = $this->sharedIpMetrics($base, $ips);
        $geo = $this->geoMetrics($ips);

        return [
            'windows' => [
                'last_10m' => (int) (clone $userQuery)->where('created_at', '>=', $now - 600)->count(),
                'last_1h' => (int) (clone $userQuery)->where('created_at', '>=', $now - 3600)->count(),
                'today' => (int) (clone $userQuery)->where('created_at', '>=', $todayStart)->count(),
                'max_per_minute' => $minuteRow ? (int) $minuteRow->total : 0,
                'min_interval' => $this->minInterval($timestamps),
            ],
            'account' => [
                'expired' => (int) $row->expired_at > 0 && (int) $row->expired_at < $now,
                'no_plan' => (int) $row->plan_id <= 0,
                'traffic_exhausted' => (int) $row->traffic_total > 0 && (int) $row->traffic_used >= (int) $row->traffic_total,
            ],
            'client' => $this->clientMetrics($agents, (clone $userQuery), $rules),
            'host' => $this->hostMetrics($hosts, $rules),
            'share' => $shared,
            'geo' => $geo,
            'traffic' => $this->trafficMetrics((clone $userQuery)),
        ];
    }

    protected function trafficMetrics($userQuery): array
    {
        $first = (clone $userQuery)->orderBy('id')->first(['traffic_used', 'traffic_total', 'created_at']);
        $latest = (clone $userQuery)->orderByDesc('id')->first(['traffic_used', 'traffic_total', 'created_at']);
        $maxUsed = (int) (clone $userQuery)->max('traffic_used');
        $minUsed = (int) (clone $userQuery)->min('traffic_used');
        $pulls = (int) (clone $userQuery)->count();
        $firstUsed = $first ? (int) $first->traffic_used : 0;
        $latestUsed = $latest ? (int) $latest->traffic_used : 0;
        $delta = max(0, $latestUsed - $firstUsed, $maxUsed - $minUsed);
        $total = $latest ? (int) $latest->traffic_total : 0;

        return [
            'pulls' => $pulls,
            'first_used' => $firstUsed,
            'latest_used' => $latestUsed,
            'max_used' => $maxUsed,
            'min_used' => $minUsed,
            'used_delta' => $delta,
            'traffic_total' => $total,
            'usage_ratio' => $total > 0 ? round($latestUsed / $total, 6) : 0,
        ];
    }

    protected function geoMetrics(array $ips): array
    {
        $regions = (new Ip2RegionService())->lookupMany($ips);
        $countries = [];
        $provinces = [];
        $cities = [];
        $isps = [];
        $asns = [];
        $asNames = [];
        $networkTypes = [];
        $riskTypes = [];
        $riskScores = [];

        foreach ($regions as $ip => $region) {
            if (!empty($region['country'])) {
                $countries[] = $region['country'];
            }
            if (!empty($region['region'])) {
                $provinces[] = $region['region'];
            }
            if (!empty($region['city'])) {
                $cities[] = $region['city'];
            }
            if (!empty($region['isp'])) {
                $isps[] = $region['isp'];
            }
            if (!empty($region['asn'])) {
                $asns[] = (int) $region['asn'];
            }
            if (!empty($region['as_name'])) {
                $asNames[] = $region['as_name'];
            }
            if (!empty($region['network_type'])) {
                $networkTypes[] = $region['network_type'];
            }
            if (!empty($region['ip_risk_type'])) {
                $riskTypes[] = $region['ip_risk_type'];
            }
            if (!empty($region['ip_risk_score'])) {
                $riskScores[] = (int) $region['ip_risk_score'];
            }
        }

        return [
            'enabled' => (new Ip2RegionService())->databaseStatus()['enabled'],
            'resolved_ip_count' => count($regions),
            'country_count' => count(array_unique($countries)),
            'region_count' => count(array_unique($provinces)),
            'city_count' => count(array_unique($cities)),
            'isp_count' => count(array_unique($isps)),
            'asn_count' => count(array_unique($asns)),
            'network_type_count' => count(array_unique($networkTypes)),
            'risk_ip_count' => count(array_unique($riskTypes)),
            'max_ip_risk_score' => $riskScores ? max($riskScores) : 0,
            'countries' => array_values(array_slice(array_unique($countries), 0, 8)),
            'regions' => array_values(array_slice(array_unique($provinces), 0, 8)),
            'cities' => array_values(array_slice(array_unique($cities), 0, 8)),
            'isps' => array_values(array_slice(array_unique($isps), 0, 8)),
            'asns' => array_values(array_slice(array_unique($asns), 0, 8)),
            'as_names' => array_values(array_slice(array_unique($asNames), 0, 8)),
            'network_types' => array_values(array_slice(array_unique($networkTypes), 0, 8)),
            'ip_risk_types' => array_values(array_slice(array_unique($riskTypes), 0, 8)),
            'ip_map' => $regions,
        ];
    }

    protected function sharedIpMetrics($base, array $ips): array
    {
        if (!$ips) {
            return [
                'max_ip_users' => 0,
                'max_ip_tokens' => 0,
                'shared_ips' => [],
            ];
        }

        $rows = (clone $base)
            ->select('client_ip', DB::raw('COUNT(DISTINCT user_id) as users'), DB::raw('COUNT(DISTINCT token_hash) as tokens'))
            ->whereIn('client_ip', $ips)
            ->whereNotNull('client_ip')
            ->groupBy('client_ip')
            ->orderByDesc('users')
            ->orderByDesc('tokens')
            ->limit(8)
            ->get();

        return [
            'max_ip_users' => (int) $rows->max('users'),
            'max_ip_tokens' => (int) $rows->max('tokens'),
            'shared_ips' => $rows->filter(function ($row) {
                return (int) $row->users >= 2 || (int) $row->tokens >= 2;
            })->map(function ($row) {
                return [
                    'client_ip' => $row->client_ip,
                    'users' => (int) $row->users,
                    'tokens' => (int) $row->tokens,
                ];
            })->values()->toArray(),
        ];
    }

    protected function clientMetrics($agents, $userQuery, array $rules): array
    {
        $suspiciousKeywords = $rules['client']['suspicious_keywords'] ?? [];
        $trustedKeywords = $rules['client']['trusted_keywords'] ?? [];
        $suspiciousAgents = [];
        $trustedAgents = [];

        foreach ($agents as $agent) {
            if ($agent === '') {
                continue;
            }
            if ($this->matchesKeyword($agent, $suspiciousKeywords)) {
                $suspiciousAgents[] = $agent;
            }
            if ($this->matchesKeyword($agent, $trustedKeywords)) {
                $trustedAgents[] = $agent;
            }
        }

        return [
            'empty_agent_hits' => (int) (clone $userQuery)->where(function ($q) {
                $q->whereNull('user_agent')->orWhere('user_agent', '');
            })->count(),
            'suspicious_agents' => array_values(array_slice(array_unique($suspiciousAgents), 0, 8)),
            'trusted_agents' => array_values(array_slice(array_unique($trustedAgents), 0, 8)),
            'suspicious_agent_count' => count(array_unique($suspiciousAgents)),
            'trusted_agent_count' => count(array_unique($trustedAgents)),
        ];
    }

    protected function hostMetrics(array $hosts, array $rules): array
    {
        $trusted = $this->normalizeStringList($rules['host_policy']['trusted_hosts'] ?? []);
        $watch = $this->normalizeStringList($rules['host_policy']['watch_hosts'] ?? []);
        $risk = $this->normalizeStringList($rules['host_policy']['risk_hosts'] ?? []);
        $trustedHits = [];
        $watchHits = [];
        $riskHits = [];
        $unknownHits = [];

        foreach ($hosts as $host) {
            $host = trim((string) $host);
            if ($host === '') {
                continue;
            }
            if ($this->matchesHost($host, $risk)) {
                $riskHits[] = $host;
                continue;
            }
            if ($this->matchesHost($host, $watch)) {
                $watchHits[] = $host;
            }
            if ($this->matchesHost($host, $trusted)) {
                $trustedHits[] = $host;
            } elseif ($trusted) {
                $unknownHits[] = $host;
            }
        }

        return [
            'trusted_hosts' => array_values(array_slice(array_unique($trustedHits), 0, 8)),
            'watch_hosts' => array_values(array_slice(array_unique($watchHits), 0, 8)),
            'risk_hosts' => array_values(array_slice(array_unique($riskHits), 0, 8)),
            'unknown_hosts' => array_values(array_slice(array_unique($unknownHits), 0, 8)),
            'trusted_host_count' => count(array_unique($trustedHits)),
            'watch_host_count' => count(array_unique($watchHits)),
            'risk_host_count' => count(array_unique($riskHits)),
            'unknown_host_count' => count(array_unique($unknownHits)),
        ];
    }

    protected function minInterval(array $timestamps): int
    {
        $timestamps = array_values(array_unique(array_filter($timestamps)));
        sort($timestamps);
        $min = 0;
        for ($i = 1; $i < count($timestamps); $i++) {
            $diff = $timestamps[$i] - $timestamps[$i - 1];
            if ($diff <= 0) {
                continue;
            }
            if ($min === 0 || $diff < $min) {
                $min = $diff;
            }
        }
        return $min;
    }

    protected function riskProfile($row, array $metrics = [], ?array $rules = null): array
    {
        $score = 0;
        $hits = [];
        $signals = [];
        $discounts = [];
        $rules = $rules ?: $this->getRiskRules();
        $ips = (int) $row->ips;
        $hosts = (int) $row->hosts;
        $agents = (int) $row->agents;
        $tokens = (int) $row->tokens;
        $total = (int) $row->total;
        $failures = (int) $row->failures;

        foreach (['ip', 'agent', 'host', 'request'] as $key) {
            $value = [
                'ip' => $ips,
                'agent' => $agents,
                'host' => $hosts,
                'request' => $total,
            ][$key];
            $rule = $rules[$key] ?? [];
            $tiers = $rule['tiers'] ?? [];
            usort($tiers, function ($a, $b) {
                return (int) ($b['threshold'] ?? 0) <=> (int) ($a['threshold'] ?? 0);
            });
            foreach ($tiers as $tier) {
                $threshold = (int) ($tier['threshold'] ?? 0);
                if ($threshold > 0 && $value >= $threshold) {
                    $this->addRiskSignal($score, $signals, $hits, $key, $this->signalLabel($key), (int) ($tier['score'] ?? 0), $value);
                    break;
                }
            }
        }

        if ($tokens >= (int) ($rules['token']['threshold'] ?? 2)) {
            $this->addRiskSignal($score, $signals, $hits, 'token', '多 Token', (int) ($rules['token']['score'] ?? 20), $tokens);
        }

        if ($failures > 0) {
            $this->addRiskSignal($score, $signals, $hits, 'failure', '失败请求', min((int) ($rules['failure']['max_score'] ?? 20), $failures * (int) ($rules['failure']['score_each'] ?? 2)), $failures);
        }

        $traffic = $metrics['traffic'] ?? [];
        $trafficRules = $rules['traffic'] ?? [];
        $noUsagePulls = (int) ($trafficRules['no_usage_pulls'] ?? 0);
        $lowUsagePulls = (int) ($trafficRules['low_usage_pulls'] ?? 0);
        $lowUsageBytes = (int) ($trafficRules['low_usage_bytes'] ?? 0);
        $normalUsageBytes = (int) ($trafficRules['normal_usage_bytes'] ?? 0);
        $normalUsageDiscount = (int) ($trafficRules['normal_usage_discount'] ?? 0);
        if ($noUsagePulls > 0 && $total >= $noUsagePulls && (int) ($traffic['latest_used'] ?? 0) <= 0) {
            $this->addRiskSignal($score, $signals, $hits, 'no_traffic_after_subscribe', '只拉订阅未用流量', (int) ($trafficRules['no_usage_score'] ?? 0), $total);
        } elseif ($lowUsagePulls > 0 && $total >= $lowUsagePulls && (int) ($traffic['used_delta'] ?? 0) <= $lowUsageBytes) {
            $this->addRiskSignal($score, $signals, $hits, 'low_traffic_after_subscribe', '高频拉取低流量', (int) ($trafficRules['low_usage_score'] ?? 0), $this->formatBytesForSignal((int) ($traffic['used_delta'] ?? 0)));
        } elseif ($normalUsageBytes > 0 && (int) ($traffic['used_delta'] ?? 0) >= $normalUsageBytes && $normalUsageDiscount > 0) {
            $this->queueRiskDiscount($discounts, 'normal_traffic_usage', '正常流量增长', $normalUsageDiscount, $this->formatBytesForSignal((int) ($traffic['used_delta'] ?? 0)));
        }

        $windows = $metrics['windows'] ?? [];
        foreach ([
            'last_10m' => '近10分钟高频',
            'last_1h' => '近1小时高频',
            'today' => '今日高频',
            'max_per_minute' => '分钟突发',
        ] as $key => $label) {
            $rule = $rules['frequency'][$key] ?? [];
            $value = (int) ($windows[$key] ?? 0);
            if ($value >= (int) ($rule['threshold'] ?? 0) && (int) ($rule['threshold'] ?? 0) > 0) {
                $this->addRiskSignal($score, $signals, $hits, $key, $label, (int) ($rule['score'] ?? 0), $value);
            }
        }
        $minInterval = (int) ($windows['min_interval'] ?? 0);
        $intervalRule = $rules['frequency']['min_interval'] ?? [];
        if ($minInterval > 0 && $minInterval <= (int) ($intervalRule['seconds'] ?? 0) && (int) ($intervalRule['seconds'] ?? 0) > 0) {
            $this->addRiskSignal($score, $signals, $hits, 'min_interval', '请求间隔过短', (int) ($intervalRule['score'] ?? 0), $minInterval . 's');
        }

        $account = $metrics['account'] ?? [];
        $accountMinRequests = (int) ($rules['account']['min_requests'] ?? 3);
        if ($total >= $accountMinRequests) {
            foreach ([
                'expired' => '账号已过期',
                'no_plan' => '无套餐拉取',
                'traffic_exhausted' => '流量用尽拉取',
            ] as $key => $label) {
                if (!empty($account[$key])) {
                    $this->addRiskSignal($score, $signals, $hits, $key, $label, (int) ($rules['account'][$key . '_score'] ?? 0), $total);
                }
            }
        }

        $client = $metrics['client'] ?? [];
        $suspiciousAgentScore = min(
            (int) ($rules['client']['suspicious_max_score'] ?? 30),
            (int) ($client['suspicious_agent_count'] ?? 0) * (int) ($rules['client']['suspicious_score_each'] ?? 10)
        );
        if ($suspiciousAgentScore > 0) {
            $this->addRiskSignal($score, $signals, $hits, 'suspicious_agent', '可疑客户端', $suspiciousAgentScore, (int) ($client['suspicious_agent_count'] ?? 0));
        }
        $emptyAgentHits = (int) ($client['empty_agent_hits'] ?? 0);
        if ($emptyAgentHits > 0) {
            $this->addRiskSignal($score, $signals, $hits, 'empty_agent', '空 User-Agent', min((int) ($rules['client']['empty_agent_max_score'] ?? 20), $emptyAgentHits * (int) ($rules['client']['empty_agent_score_each'] ?? 2)), $emptyAgentHits);
        }
        if ((int) ($client['trusted_agent_count'] ?? 0) > 0 && (int) ($rules['client']['trusted_discount'] ?? 0) > 0) {
            $this->queueRiskDiscount($discounts, 'trusted_client', '可信客户端', (int) $rules['client']['trusted_discount'], (int) ($client['trusted_agent_count'] ?? 0));
        }

        $host = $metrics['host'] ?? [];
        if ((int) ($host['trusted_host_count'] ?? 0) > 0 && (int) ($rules['host_policy']['trusted_discount'] ?? 0) > 0) {
            $this->queueRiskDiscount($discounts, 'trusted_host', '可信入口', (int) $rules['host_policy']['trusted_discount'], (int) ($host['trusted_host_count'] ?? 0));
        }

        foreach ([
            'risk_host' => ['count' => 'risk_host_count', 'label' => '高风险入口', 'score' => 'risk_host_score'],
            'watch_host' => ['count' => 'watch_host_count', 'label' => '观察入口', 'score' => 'watch_host_score'],
            'unknown_host' => ['count' => 'unknown_host_count', 'label' => '非可信入口', 'score' => 'unknown_host_score'],
        ] as $code => $meta) {
            $value = (int) ($host[$meta['count']] ?? 0);
            if ($value > 0) {
                $this->addRiskSignal($score, $signals, $hits, $code, $meta['label'], min((int) ($rules['host_policy']['max_score'] ?? 30), $value * (int) ($rules['host_policy'][$meta['score']] ?? 0)), $value);
            }
        }

        $share = $metrics['share'] ?? [];
        foreach ([
            'shared_ip_users' => ['value' => 'max_ip_users', 'label' => '同 IP 多账号', 'rule' => 'ip_users'],
            'shared_ip_tokens' => ['value' => 'max_ip_tokens', 'label' => '同 IP 多 Token', 'rule' => 'ip_tokens'],
        ] as $code => $meta) {
            $rule = $rules['share'][$meta['rule']] ?? [];
            $value = (int) ($share[$meta['value']] ?? 0);
            if ($value >= (int) ($rule['threshold'] ?? 0) && (int) ($rule['threshold'] ?? 0) > 0) {
                $this->addRiskSignal($score, $signals, $hits, $code, $meta['label'], (int) ($rule['score'] ?? 0), $value);
            }
        }

        $geo = $metrics['geo'] ?? [];
        if (!empty($geo['enabled'])) {
            foreach ([
                'geo_country' => ['value' => 'country_count', 'label' => '多国家/地区', 'rule' => 'countries'],
                'geo_region' => ['value' => 'region_count', 'label' => '多省份/区域', 'rule' => 'regions'],
                'geo_city' => ['value' => 'city_count', 'label' => '多城市', 'rule' => 'cities'],
                'geo_isp' => ['value' => 'isp_count', 'label' => '多运营商', 'rule' => 'isps'],
                'geo_asn' => ['value' => 'asn_count', 'label' => '多 ASN', 'rule' => 'asns'],
                'network_type' => ['value' => 'network_type_count', 'label' => '多网络类型', 'rule' => 'network_types'],
            ] as $code => $meta) {
                $rule = $rules['geo'][$meta['rule']] ?? [];
                $value = (int) ($geo[$meta['value']] ?? 0);
                if ($value >= (int) ($rule['threshold'] ?? 0) && (int) ($rule['threshold'] ?? 0) > 0) {
                    $this->addRiskSignal($score, $signals, $hits, $code, $meta['label'], (int) ($rule['score'] ?? 0), $value);
                }
            }
            $networkRules = $rules['network'] ?? [];
            $networkTypes = $geo['network_types'] ?? [];
            foreach ([
                'idc' => 'IDC/机房网络',
                'mobile' => '移动网络',
                'fixed' => '家宽/固定宽带',
            ] as $type => $label) {
                if (in_array($type, $networkTypes, true) && (int) ($networkRules[$type . '_score'] ?? 0) > 0) {
                    $this->addRiskSignal($score, $signals, $hits, 'network_' . $type, $label, (int) $networkRules[$type . '_score'], $label);
                }
            }
            $riskTypes = $geo['ip_risk_types'] ?? [];
            foreach ([
                'proxy' => '代理 IP',
                'vpn' => 'VPN IP',
                'tor' => 'Tor 出口',
                'bot' => '自动化/爬虫 IP',
            ] as $type => $label) {
                if (in_array($type, $riskTypes, true) && (int) ($networkRules[$type . '_score'] ?? 0) > 0) {
                    $this->addRiskSignal($score, $signals, $hits, 'ip_' . $type, $label, (int) $networkRules[$type . '_score'], $label);
                }
            }
        }

        $this->applyRiskDiscounts($score, $signals, $discounts);

        if ($this->shouldDowngradeCritical($score, $signals, $traffic, $rules)) {
            $this->addRiskDiscount($score, $signals, 'critical_guard', '极危险组合保护', (int) (($rules['guard']['critical_downgrade_discount'] ?? 0)), '正常流量或缺少核心证据');
        }

        if ($score >= (int) ($rules['levels']['critical'] ?? 80)) {
            $level = '极危险';
        } elseif ($score >= (int) ($rules['levels']['high'] ?? 50)) {
            $level = '高风险';
        } elseif ($score >= (int) ($rules['levels']['medium'] ?? 25)) {
            $level = '中风险';
        } else {
            $level = '无风险';
        }

        return [
            'level' => $level,
            'score' => min(100, $score),
            'reasons' => $hits,
            'signals' => $signals,
        ];
    }

    protected function defaultRiskRules(): array
    {
        return [
            'levels' => [
                'medium' => 30,
                'high' => 60,
                'critical' => 85,
            ],
            'ip' => [
                'tiers' => [
                    ['threshold' => 2, 'score' => 6],
                    ['threshold' => 4, 'score' => 14],
                    ['threshold' => 8, 'score' => 30],
                ],
            ],
            'agent' => [
                'tiers' => [
                    ['threshold' => 3, 'score' => 8],
                    ['threshold' => 6, 'score' => 20],
                ],
            ],
            'host' => [
                'tiers' => [
                    ['threshold' => 2, 'score' => 5],
                    ['threshold' => 4, 'score' => 14],
                ],
            ],
            'request' => [
                'tiers' => [
                    ['threshold' => 30, 'score' => 8],
                    ['threshold' => 80, 'score' => 18],
                    ['threshold' => 200, 'score' => 35],
                ],
            ],
            'token' => [
                'threshold' => 2,
                'score' => 20,
            ],
            'failure' => [
                'score_each' => 2,
                'max_score' => 20,
            ],
            'frequency' => [
                'last_10m' => ['threshold' => 10, 'score' => 8],
                'last_1h' => ['threshold' => 40, 'score' => 10],
                'today' => ['threshold' => 150, 'score' => 14],
                'max_per_minute' => ['threshold' => 6, 'score' => 10],
                'min_interval' => ['seconds' => 10, 'score' => 6],
            ],
            'account' => [
                'min_requests' => 3,
                'expired_score' => 18,
                'no_plan_score' => 12,
                'traffic_exhausted_score' => 15,
            ],
            'traffic' => [
                'no_usage_pulls' => 5,
                'no_usage_score' => 45,
                'low_usage_pulls' => 8,
                'low_usage_bytes' => 10485760,
                'low_usage_score' => 32,
                'normal_usage_bytes' => 52428800,
                'normal_usage_discount' => 25,
            ],
            'client' => [
                'suspicious_keywords' => ['curl', 'wget', 'python', 'go-http-client', 'postman', 'okhttp', 'httpclient'],
                'trusted_keywords' => ['FlClash', 'Clash', 'sing-box', 'v2rayN', 'Shadowrocket', 'Stash'],
                'suspicious_score_each' => 10,
                'suspicious_max_score' => 30,
                'empty_agent_score_each' => 2,
                'empty_agent_max_score' => 20,
                'trusted_discount' => 8,
            ],
            'host_policy' => [
                'trusted_hosts' => [],
                'watch_hosts' => [],
                'risk_hosts' => [],
                'watch_host_score' => 8,
                'risk_host_score' => 25,
                'unknown_host_score' => 6,
                'max_score' => 30,
                'trusted_discount' => 8,
            ],
            'share' => [
                'ip_users' => ['threshold' => 3, 'score' => 18],
                'ip_tokens' => ['threshold' => 3, 'score' => 18],
            ],
            'geo' => [
                'countries' => ['threshold' => 2, 'score' => 18],
                'regions' => ['threshold' => 3, 'score' => 12],
                'cities' => ['threshold' => 4, 'score' => 10],
                'isps' => ['threshold' => 3, 'score' => 10],
                'asns' => ['threshold' => 3, 'score' => 15],
                'network_types' => ['threshold' => 2, 'score' => 12],
            ],
            'network' => [
                'idc_score' => 10,
                'mobile_score' => 0,
                'fixed_score' => 0,
                'proxy_score' => 35,
                'vpn_score' => 30,
                'tor_score' => 45,
                'bot_score' => 30,
            ],
            'guard' => [
                'critical_requires_core_signal' => true,
                'critical_downgrade_discount' => 20,
            ],
            'queue' => [
                'watch_score' => 80,
            ],
        ];
    }

    protected function normalizeRiskRules(array $data): array
    {
        $rules = $this->getRiskRules();
        foreach (['medium', 'high', 'critical'] as $key) {
            if (isset($data['levels'][$key])) {
                $rules['levels'][$key] = max(0, min(100, (int) $data['levels'][$key]));
            }
        }
        foreach (['ip', 'agent', 'host', 'request'] as $key) {
            if (!isset($data[$key]['tiers']) || !is_array($data[$key]['tiers'])) {
                continue;
            }
            $tiers = [];
            foreach ($data[$key]['tiers'] as $tier) {
                $tiers[] = [
                    'threshold' => max(0, (int) ($tier['threshold'] ?? 0)),
                    'score' => max(0, min(100, (int) ($tier['score'] ?? 0))),
                ];
            }
            $rules[$key]['tiers'] = $tiers;
        }
        foreach (['threshold', 'score'] as $key) {
            if (isset($data['token'][$key])) {
                $rules['token'][$key] = max(0, min(100, (int) $data['token'][$key]));
            }
        }
        foreach (['score_each', 'max_score'] as $key) {
            if (isset($data['failure'][$key])) {
                $rules['failure'][$key] = max(0, min(100, (int) $data['failure'][$key]));
            }
        }
        foreach (['last_10m', 'last_1h', 'today', 'max_per_minute'] as $key) {
            if (isset($data['frequency'][$key]) && is_array($data['frequency'][$key])) {
                $rules['frequency'][$key]['threshold'] = max(0, (int) ($data['frequency'][$key]['threshold'] ?? $rules['frequency'][$key]['threshold']));
                $rules['frequency'][$key]['score'] = max(0, min(100, (int) ($data['frequency'][$key]['score'] ?? $rules['frequency'][$key]['score'])));
            }
        }
        if (isset($data['frequency']['min_interval']) && is_array($data['frequency']['min_interval'])) {
            $rules['frequency']['min_interval']['seconds'] = max(0, (int) ($data['frequency']['min_interval']['seconds'] ?? $rules['frequency']['min_interval']['seconds']));
            $rules['frequency']['min_interval']['score'] = max(0, min(100, (int) ($data['frequency']['min_interval']['score'] ?? $rules['frequency']['min_interval']['score'])));
        }
        foreach (['min_requests', 'expired_score', 'no_plan_score', 'traffic_exhausted_score'] as $key) {
            if (isset($data['account'][$key])) {
                $rules['account'][$key] = max(0, min(1000, (int) $data['account'][$key]));
            }
        }
        foreach (['no_usage_pulls', 'low_usage_pulls', 'low_usage_bytes', 'normal_usage_bytes'] as $key) {
            if (isset($data['traffic'][$key])) {
                $rules['traffic'][$key] = max(0, (int) $data['traffic'][$key]);
            }
        }
        foreach (['no_usage_score', 'low_usage_score', 'normal_usage_discount'] as $key) {
            if (isset($data['traffic'][$key])) {
                $rules['traffic'][$key] = max(0, min(100, (int) $data['traffic'][$key]));
            }
        }
        foreach (['suspicious_score_each', 'suspicious_max_score', 'empty_agent_score_each', 'empty_agent_max_score', 'trusted_discount'] as $key) {
            if (isset($data['client'][$key])) {
                $rules['client'][$key] = max(0, min(100, (int) $data['client'][$key]));
            }
        }
        foreach (['suspicious_keywords', 'trusted_keywords'] as $key) {
            if (isset($data['client'][$key])) {
                $rules['client'][$key] = $this->normalizeStringList($data['client'][$key]);
            }
        }
        foreach (['watch_host_score', 'risk_host_score', 'unknown_host_score', 'max_score'] as $key) {
            if (isset($data['host_policy'][$key])) {
                $rules['host_policy'][$key] = max(0, min(100, (int) $data['host_policy'][$key]));
            }
        }
        if (isset($data['host_policy']['trusted_discount'])) {
            $rules['host_policy']['trusted_discount'] = max(0, min(100, (int) $data['host_policy']['trusted_discount']));
        }
        foreach (['trusted_hosts', 'watch_hosts', 'risk_hosts'] as $key) {
            if (isset($data['host_policy'][$key])) {
                $rules['host_policy'][$key] = $this->normalizeStringList($data['host_policy'][$key]);
            }
        }
        foreach (['ip_users', 'ip_tokens'] as $key) {
            if (isset($data['share'][$key]) && is_array($data['share'][$key])) {
                $rules['share'][$key]['threshold'] = max(0, (int) ($data['share'][$key]['threshold'] ?? $rules['share'][$key]['threshold']));
                $rules['share'][$key]['score'] = max(0, min(100, (int) ($data['share'][$key]['score'] ?? $rules['share'][$key]['score'])));
            }
        }
        foreach (['countries', 'regions', 'cities', 'isps', 'asns', 'network_types'] as $key) {
            if (isset($data['geo'][$key]) && is_array($data['geo'][$key])) {
                $rules['geo'][$key]['threshold'] = max(0, (int) ($data['geo'][$key]['threshold'] ?? $rules['geo'][$key]['threshold']));
                $rules['geo'][$key]['score'] = max(0, min(100, (int) ($data['geo'][$key]['score'] ?? $rules['geo'][$key]['score'])));
            }
        }
        foreach (['idc_score', 'mobile_score', 'fixed_score', 'proxy_score', 'vpn_score', 'tor_score', 'bot_score'] as $key) {
            if (isset($data['network'][$key])) {
                $rules['network'][$key] = max(0, min(100, (int) $data['network'][$key]));
            }
        }
        if (isset($data['guard']) && is_array($data['guard'])) {
            if (isset($data['guard']['critical_requires_core_signal'])) {
                $rules['guard']['critical_requires_core_signal'] = (bool) $data['guard']['critical_requires_core_signal'];
            }
            if (isset($data['guard']['critical_downgrade_discount'])) {
                $rules['guard']['critical_downgrade_discount'] = max(0, min(100, (int) $data['guard']['critical_downgrade_discount']));
            }
        }
        if (isset($data['queue']) && is_array($data['queue']) && isset($data['queue']['watch_score'])) {
            $rules['queue']['watch_score'] = max(0, min(100, (int) $data['queue']['watch_score']));
        }
        return $rules;
    }

    protected function recent($base, int $limit): array
    {
        return (clone $base)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->user_id,
                    'email' => $row->email,
                    'token_hash' => $this->shortHash($row->token_hash),
                    'subscribe_type' => $row->subscribe_type,
                    'flag' => $row->flag,
                    'request_host' => $row->request_host,
                    'request_path' => $row->request_path,
                    'client_ip' => $row->client_ip,
                    'real_ip_source' => $row->real_ip_source,
                    'user_agent' => $row->user_agent,
                    'plan_id' => (int) $row->plan_id,
                    'traffic_used' => (int) $row->traffic_used,
                    'traffic_total' => (int) $row->traffic_total,
                    'expired_at' => (int) $row->expired_at,
                    'status' => (int) $row->status,
                    'created_at' => (int) $row->created_at,
                ];
            })
            ->toArray();
    }

    protected function filteredQuery(array $filters)
    {
        $query = SubscribeAccessLog::query();
        if ($filters['days'] > 0) {
            $query->where('created_at', '>=', time() - $filters['days'] * 86400);
        }
        if ($filters['keyword'] !== '') {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('email', 'like', "%{$keyword}%")
                    ->orWhere('client_ip', 'like', "%{$keyword}%")
                    ->orWhere('request_host', 'like', "%{$keyword}%")
                    ->orWhere('request_path', 'like', "%{$keyword}%")
                    ->orWhere('token_hash', 'like', "%{$keyword}%");
            });
        }
        if ($filters['type'] !== '') {
            $query->where('subscribe_type', $filters['type']);
        }
        if ($filters['risk'] !== '' && $this->snapshotDataExists()) {
            $userIds = $this->latestSnapshotUserIdsByRisk($filters['risk']);
            if (!$userIds) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('user_id', $userIds);
            }
        }
        if ($filters['disposition'] !== '') {
            $this->applyDispositionStatusFilter($query, $filters['disposition']);
        }
        if ($filters['disposition_keyword'] !== '' || $filters['operator'] !== '') {
            $userIds = $this->filteredDispositionUserIds($filters);
            if (!$userIds) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('user_id', $userIds);
            }
        }
        return $query;
    }

    protected function normalizeFilters(array $filters): array
    {
        $days = (int) ($filters['days'] ?? 7);
        if ($days < 1) $days = 7;
        if ($days > 90) $days = 90;

        $limit = (int) ($filters['limit'] ?? 80);
        if ($limit < 10) $limit = 10;
        if ($limit > 200) $limit = 200;

        $page = (int) ($filters['page'] ?? 1);
        if ($page < 1) $page = 1;

        $perPage = (int) ($filters['per_page'] ?? 50);
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        return [
            'days' => $days,
            'limit' => $limit,
            'page' => $page,
            'per_page' => $perPage,
            'keyword' => trim((string) ($filters['keyword'] ?? '')),
            'type' => trim((string) ($filters['type'] ?? '')),
            'risk' => $this->normalizeRiskFilter($filters['risk'] ?? ''),
            'disposition' => $this->normalizeDispositionFilter($filters['disposition'] ?? ''),
            'disposition_keyword' => trim((string) ($filters['disposition_keyword'] ?? '')),
            'operator' => trim((string) ($filters['operator'] ?? '')),
            'watch_overdue_days' => max(1, min(365, (int) ($filters['watch_overdue_days'] ?? 7))),
        ];
    }

    protected function paginationMeta($base, array $filters): array
    {
        $totalUsers = (int) (clone $base)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');
        $perPage = max(1, (int) ($filters['per_page'] ?? 50));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_users' => $totalUsers,
            'total_pages' => max(1, (int) ceil($totalUsers / $perPage)),
        ];
    }

    protected function emptySummary(): array
    {
        return [
            'total' => 0,
            'today' => 0,
            'unique_users' => 0,
            'unique_ips' => 0,
            'unique_tokens' => 0,
            'range_days' => 0,
        ];
    }

    protected function emptyRiskSummary(): array
    {
        return [
            'safe' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];
    }

    protected function resolveIp(Request $request): array
    {
        $headers = [
            'cf-connecting-ip' => 'cf-connecting-ip',
            'x-forwarded-for' => 'x-forwarded-for',
            'x-real-ip' => 'x-real-ip',
        ];

        foreach ($headers as $header => $source) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }
            if ($header === 'x-forwarded-for') {
                $parts = array_filter(array_map('trim', explode(',', $value)));
                $value = (string) reset($parts);
            }
            if ($value !== '') {
                return [$value, $source];
            }
        }

        return [$request->ip(), 'request_ip'];
    }

    protected function tableExists(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        try {
            self::$tableReady = Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            self::$tableReady = false;
        }
        return self::$tableReady;
    }

    protected function dispositionTableExists(): bool
    {
        if (self::$dispositionTableReady !== null) {
            return self::$dispositionTableReady;
        }
        try {
            self::$dispositionTableReady = Schema::hasTable(self::DISPOSITION_TABLE);
        } catch (\Throwable $e) {
            self::$dispositionTableReady = false;
        }
        return self::$dispositionTableReady;
    }

    protected function dispositionLogTableExists(): bool
    {
        if (self::$dispositionLogTableReady !== null) {
            return self::$dispositionLogTableReady;
        }
        try {
            self::$dispositionLogTableReady = Schema::hasTable(self::DISPOSITION_LOG_TABLE);
        } catch (\Throwable $e) {
            self::$dispositionLogTableReady = false;
        }
        return self::$dispositionLogTableReady;
    }

    protected function snapshotTableExists(): bool
    {
        if (self::$snapshotTableReady !== null) {
            return self::$snapshotTableReady;
        }
        try {
            self::$snapshotTableReady = Schema::hasTable(self::SNAPSHOT_TABLE);
        } catch (\Throwable $e) {
            self::$snapshotTableReady = false;
        }
        return self::$snapshotTableReady;
    }

    protected function snapshotDataExists(): bool
    {
        if (!$this->snapshotTableExists()) {
            return false;
        }
        try {
            return SubscribeRiskSnapshot::query()->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function filteredDispositionUserIds(array $filters): array
    {
        if (!$this->dispositionTableExists()) {
            return [];
        }

        $query = SubscribeDisposition::query();
        if ($filters['disposition_keyword'] !== '') {
            $keyword = $filters['disposition_keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('note', 'like', "%{$keyword}%")
                    ->orWhere('operator_email', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            });
        }
        if ($filters['operator'] !== '') {
            $operator = $filters['operator'];
            $query->where('operator_email', 'like', "%{$operator}%");
        }

        return array_values(array_unique(array_map('intval', $query->pluck('user_id')->toArray())));
    }

    protected function applyDispositionStatusFilter($query, string $status): void
    {
        if (!$this->dispositionTableExists()) {
            if ($status !== 'none') {
                $query->whereRaw('1 = 0');
            }
            return;
        }

        $handledIds = array_values(array_unique(array_map('intval', SubscribeDisposition::pluck('user_id')->toArray())));
        if ($status === 'none') {
            if ($handledIds) {
                $query->whereNotIn('user_id', $handledIds);
            }
            return;
        }

        $userIds = array_values(array_unique(array_map('intval', SubscribeDisposition::where('status', $status)->pluck('user_id')->toArray())));
        if (!$userIds) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('user_id', $userIds);
        }
    }

    protected function latestSnapshotUserIdsByRisk(string $risk): array
    {
        if (!$this->snapshotTableExists()) {
            return [];
        }

        $level = [
            'critical' => '极危险',
            'high' => '高风险',
            'medium' => '中风险',
            'safe' => '无风险',
        ][$risk] ?? null;

        if ($level === null) {
            return [];
        }

        $latest = SubscribeRiskSnapshot::query()
            ->select('user_id', DB::raw('MAX(snapshot_at) as latest_snapshot_at'))
            ->groupBy('user_id');

        return array_values(array_unique(array_map('intval', SubscribeRiskSnapshot::query()
            ->joinSub($latest, 'latest_snapshots', function ($join) {
                $join->on('v2_subscribe_risk_snapshots.user_id', '=', 'latest_snapshots.user_id')
                    ->on('v2_subscribe_risk_snapshots.snapshot_at', '=', 'latest_snapshots.latest_snapshot_at');
            })
            ->where('v2_subscribe_risk_snapshots.risk_level', $level)
            ->pluck('v2_subscribe_risk_snapshots.user_id')
            ->toArray())));
    }

    protected function dispositionsForRows($rows, array $filters = []): array
    {
        if (!$this->dispositionTableExists()) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->user_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }

        $items = [];
        foreach (SubscribeDisposition::whereIn('user_id', $ids)->get() as $row) {
            $items[(int) $row->user_id] = $this->dispositionRowToArray($row);
        }
        return $items;
    }

    protected function dispositionFilterOptions(): array
    {
        if (!$this->dispositionTableExists()) {
            return [
                'operators' => [],
                'watch_overdue_days' => 7,
            ];
        }

        return [
            'operators' => SubscribeDisposition::whereNotNull('operator_email')
                ->where('operator_email', '<>', '')
                ->distinct()
                ->orderBy('operator_email', 'ASC')
                ->limit(50)
                ->pluck('operator_email')
                ->values()
                ->toArray(),
            'watch_overdue_days' => 7,
        ];
    }

    protected function dispositionOverdue(array $disposition, array $filters): array
    {
        $status = $disposition['status'] ?? 'none';
        $start = (int) ($disposition['created_at'] ?? $disposition['updated_at'] ?? 0);
        $days = $start > 0 ? (int) floor((time() - $start) / 86400) : 0;
        $threshold = max(1, (int) ($filters['watch_overdue_days'] ?? 7));

        return [
            'enabled' => in_array($status, ['watch', 'freeze_suggested', 'blacklist_suggested'], true),
            'days' => max(0, $days),
            'threshold_days' => $threshold,
            'overdue' => in_array($status, ['watch', 'freeze_suggested', 'blacklist_suggested'], true) && $days >= $threshold,
        ];
    }

    protected function dispositionRowToArray($row): array
    {
        return [
            'status' => $row->status ?: 'none',
            'label' => $this->dispositionLabel($row->status ?: 'none'),
            'level' => $row->level,
            'note' => $row->note,
            'operator_id' => $row->operator_id !== null ? (int) $row->operator_id : null,
            'operator_email' => $row->operator_email,
            'handled_at' => $row->handled_at !== null ? (int) $row->handled_at : null,
            'expires_at' => $row->expires_at !== null ? (int) $row->expires_at : null,
            'created_at' => $row->created_at !== null ? (int) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (int) $row->updated_at : null,
        ];
    }

    protected function emptyDisposition(): array
    {
        return [
            'status' => 'none',
            'label' => $this->dispositionLabel('none'),
            'level' => null,
            'note' => null,
            'operator_id' => null,
            'operator_email' => null,
            'handled_at' => null,
            'expires_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    protected function dispositionLabel(string $status): string
    {
        return [
            'none' => '未处置',
            'watch' => '加入观察',
            'handled' => '已处理',
            'whitelist' => '白名单',
            'freeze_suggested' => '建议冻结',
            'blacklist_suggested' => '建议拉黑',
        ][$status] ?? $status;
    }

    protected function normalizeDispositionStatus($status): string
    {
        $status = trim((string) $status);
        return in_array($status, self::DISPOSITION_STATUSES, true) ? $status : 'watch';
    }

    protected function normalizeRiskLevel($level): ?string
    {
        $level = trim((string) $level);
        return in_array($level, self::RISK_LEVELS, true) ? $level : null;
    }

    protected function normalizeRiskFilter($risk): string
    {
        $risk = trim((string) $risk);
        return in_array($risk, ['safe', 'medium', 'high', 'critical'], true) ? $risk : '';
    }

    protected function normalizeDispositionFilter($status): string
    {
        $status = trim((string) $status);
        return in_array($status, self::DISPOSITION_STATUSES, true) ? $status : '';
    }

    protected function riskKey(string $level): string
    {
        if ($level === '极危险') {
            return 'critical';
        }
        if ($level === '高风险') {
            return 'high';
        }
        if ($level === '中风险') {
            return 'medium';
        }
        return 'safe';
    }

    protected function getUserValue($user, string $key)
    {
        if (is_array($user)) {
            return $user[$key] ?? null;
        }
        if (is_object($user)) {
            return $user->{$key} ?? null;
        }
        return null;
    }

    protected function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    protected function limitString($value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        return substr($value, 0, $length);
    }

    protected function shortHash(?string $hash): ?string
    {
        if (!$hash) {
            return null;
        }
        return substr($hash, 0, 12);
    }

    protected function addRiskSignal(int &$score, array &$signals, array &$hits, string $code, string $label, int $points, $value): void
    {
        if ($points <= 0) {
            return;
        }
        $score += $points;
        $hits[] = $code;
        $signals[] = [
            'code' => $code,
            'label' => $label,
            'score' => $points,
            'value' => $value,
        ];
    }

    protected function addRiskDiscount(int &$score, array &$signals, string $code, string $label, int $points, $value): void
    {
        if ($points <= 0) {
            return;
        }
        $score = max(0, $score - $points);
        $signals[] = [
            'code' => $code,
            'label' => $label,
            'score' => -$points,
            'value' => $value,
        ];
    }

    protected function queueRiskDiscount(array &$discounts, string $code, string $label, int $points, $value): void
    {
        if ($points <= 0) {
            return;
        }
        $discounts[] = [
            'code' => $code,
            'label' => $label,
            'points' => $points,
            'value' => $value,
        ];
    }

    protected function applyRiskDiscounts(int &$score, array &$signals, array $discounts): void
    {
        foreach ($discounts as $discount) {
            $this->addRiskDiscount(
                $score,
                $signals,
                (string) ($discount['code'] ?? ''),
                (string) ($discount['label'] ?? ''),
                (int) ($discount['points'] ?? 0),
                $discount['value'] ?? ''
            );
        }
    }

    protected function shouldDowngradeCritical(int $score, array $signals, array $traffic, array $rules): bool
    {
        $threshold = (int) ($rules['levels']['critical'] ?? 80);
        if ($score < $threshold || empty($rules['guard']['critical_requires_core_signal'])) {
            return false;
        }

        $coreSignals = [
            'no_traffic_after_subscribe',
            'low_traffic_after_subscribe',
            'shared_ip_users',
            'shared_ip_tokens',
            'ip_proxy',
            'ip_vpn',
            'ip_tor',
            'ip_bot',
            'suspicious_agent',
            'risk_host',
        ];
        foreach ($signals as $signal) {
            if (in_array($signal['code'] ?? '', $coreSignals, true) && (int) ($signal['score'] ?? 0) > 0) {
                return false;
            }
        }

        $normalUsageBytes = (int) ($rules['traffic']['normal_usage_bytes'] ?? 0);
        return $normalUsageBytes <= 0 || (int) ($traffic['used_delta'] ?? 0) >= $normalUsageBytes;
    }

    protected function signalLabel(string $key): string
    {
        return [
            'ip' => '多 IP',
            'agent' => '多客户端',
            'host' => '多入口',
            'request' => '请求次数',
        ][$key] ?? $key;
    }

    protected function formatBytesForSignal(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $number = max(0, $value);
        while ($number >= 1024 && $index < count($units) - 1) {
            $number = $number / 1024;
            $index++;
        }
        return ($index === 0 ? (string) (int) $number : number_format($number, 2)) . ' ' . $units[$index];
    }

    protected function normalizeStringList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return array_values(array_unique($items));
    }

    protected function matchesKeyword(string $value, array $keywords): bool
    {
        $value = strtolower($value);
        foreach ($this->normalizeStringList($keywords) as $keyword) {
            if ($keyword !== '' && strpos($value, strtolower($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function matchesHost(string $host, array $patterns): bool
    {
        $host = strtolower($host);
        $hostOnly = strtolower((string) parse_url((strpos($host, '://') === false ? 'http://' : '') . $host, PHP_URL_HOST));
        foreach ($this->normalizeStringList($patterns) as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($host === $pattern || $hostOnly === $pattern || strpos($host, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
