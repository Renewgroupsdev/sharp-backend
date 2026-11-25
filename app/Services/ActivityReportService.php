<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * What people did, from the audit log, for the dashboard charts: actions per day by kind, the share of each kind, the
 * busiest people and the latest actions. Every action the app records (marking attendance, grading, issuing a certificate,
 * creating a user...) is in the audit log, so this covers all of them. An action's kind comes from its wording.
 */
class ActivityReportService
{
    /** Kind => regular expression on the lower-cased action text. The first match wins; anything else is "Other". */
    private const KINDS = [
        'Notifications' => 'notification',
        'Community' => 'community|common floor|chat',
        'Certificates' => 'certificate',
        'Assessments' => 'assignment|quiz|assessment|question',
        'Attendance' => 'attendance|^marked ',
        'Learning' => 'module|lesson|course|enrol|batch|unlock',
        'Users & access' => 'user|password|suspend|activat|login|role',
    ];

    /** Fixed order, so a kind keeps its colour whatever the filter shows. */
    public static function kinds(): array
    {
        return [...array_keys(self::KINDS), 'Other'];
    }

    public static function kindOf(string $action): string
    {
        $text = mb_strtolower($action);
        foreach (self::KINDS as $kind => $pattern) {
            if (preg_match('/'.$pattern.'/', $text)) {
                return $kind;
            }
        }

        return 'Other';
    }

    /** @return array<string, mixed> */
    public function report(int $days = 14): array
    {
        $days = max(1, min($days, 90));
        $from = today()->subDays($days - 1);

        $rows = AuditLog::query()->where('created_at', '>=', $from)
            ->selectRaw($this->kindSql().' AS kind, created_at::date AS day, COUNT(*) AS n')
            ->groupBy('kind', 'day')->get();

        $byDay = [];
        for ($i = 0; $i < $days; $i++) {
            $byDay[$from->copy()->addDays($i)->toDateString()] = array_fill_keys(self::kinds(), 0);
        }
        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            if (isset($byDay[$day])) {
                $byDay[$day][$row->kind] = (int) $row->n;
            }
        }

        $totals = array_fill_keys(self::kinds(), 0);
        foreach ($byDay as $kinds) {
            foreach ($kinds as $kind => $n) {
                $totals[$kind] += $n;
            }
        }

        return [
            'days' => $days,
            'total' => array_sum($totals),
            'kinds' => array_map(fn (string $k) => ['name' => $k, 'count' => $totals[$k]], self::kinds()),
            'by_day' => array_map(fn (string $date, array $by) => ['date' => $date, 'total' => array_sum($by), 'by' => $by], array_keys($byDay), $byDay),
            'people' => $this->people($from),
            'recent' => $this->recent(),
        ];
    }

    private function kindSql(): string
    {
        $when = collect(self::KINDS)->map(fn (string $re, string $kind) => "WHEN lower(action) ~ '{$re}' THEN '{$kind}'")->implode(' ');

        return "CASE {$when} ELSE 'Other' END";
    }

    /** @return list<array{name: string, role: string, count: int}> */
    private function people($from): array
    {
        return AuditLog::query()->where('audit_logs.created_at', '>=', $from)->whereNotNull('actor_id')
            ->join('users', 'users.id', '=', 'audit_logs.actor_id')
            ->select('users.name', 'users.role', DB::raw('COUNT(*) AS n'))
            ->groupBy('users.id', 'users.name', 'users.role')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['name' => $r->name, 'role' => $r->role instanceof \BackedEnum ? $r->role->value : (string) $r->role, 'count' => (int) $r->n])->all();
    }

    /** @return list<array{action: string, by: string|null, at: string, kind: string}> */
    private function recent(): array
    {
        return AuditLog::with('actor:id,name')->latest('created_at')->latest('id')->limit(10)->get()
            ->map(fn (AuditLog $l) => ['action' => $l->action, 'by' => $l->actor?->name, 'at' => $l->created_at->toIso8601String(), 'kind' => self::kindOf($l->action)])->all();
    }
}
