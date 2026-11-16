<?php

namespace App\Support\Metrics;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserMetrics
{
    /**
     * @param  array{user_id?: int, project_id?: int, from?: \DateTimeInterface|string, to?: \DateTimeInterface|string, day_ago?: \DateTimeInterface|string, week_start?: \DateTimeInterface|string}  $scope
     * @return array{total: int, members: int, active: int, pending: int, inactive: int, active_admins: int}
     */
    public function counts(array $scope = []): array
    {
        $statuses = config('metrics.users.statuses');
        $adminRole = config('metrics.users.admin_role');
        $query = User::query();

        if (isset($scope['user_id'])) {
            $query->whereKey($scope['user_id']);
        }

        if (isset($scope['project_id'])) {
            $projectId = $scope['project_id'];
            $query->where(function (Builder $query) use ($projectId): void {
                $query->whereHas('ownedProjects', fn (Builder $projects): Builder => $projects->whereKey($projectId))
                    ->orWhereHas('createdTasks', fn (Builder $tasks): Builder => $tasks->where('project_id', $projectId))
                    ->orWhereHas('assignedTasks', fn (Builder $tasks): Builder => $tasks->where('project_id', $projectId));
            });
        }

        if (isset($scope['from'])) {
            $query->where('created_at', '>=', $scope['from']);
        }

        if (isset($scope['to'])) {
            $query->where('created_at', '<=', $scope['to']);
        }

        $aggregate = $query
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'SUM(CASE WHEN is_active = ? AND last_login_at IS NOT NULL THEN 1 ELSE 0 END) AS active',
                [$statuses['active']['is_active']],
            )
            ->selectRaw(
                'SUM(CASE WHEN is_active = ? AND last_login_at IS NULL THEN 1 ELSE 0 END) AS pending',
                [$statuses['pending']['is_active']],
            )
            ->selectRaw(
                'SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) AS inactive',
                [$statuses['inactive']['is_active']],
            )
            ->selectRaw(
                'SUM(CASE WHEN role = ? AND is_active = ? THEN 1 ELSE 0 END) AS active_admins',
                [$adminRole, $statuses['active']['is_active']],
            )
            ->selectRaw('SUM(CASE WHEN role != ? THEN 1 ELSE 0 END) AS members', [$adminRole])
            ->toBase()
            ->first();

        return [
            'total' => (int) $aggregate->total,
            'members' => (int) $aggregate->members,
            'active' => (int) $aggregate->active,
            'pending' => (int) $aggregate->pending,
            'inactive' => (int) $aggregate->inactive,
            'active_admins' => (int) $aggregate->active_admins,
        ];
    }

    /**
     * @param  array{user_id?: int, project_id?: int, from?: \DateTimeInterface|string, to?: \DateTimeInterface|string}  $scope
     * @return array<string, int>
     */
    public function overview(array $scope = []): array
    {
        $query = User::query();
        $this->applyScope($query, $scope);

        return [
            'new_this_week' => (int) (clone $query)->where('created_at', '>=', $scope['week_start'] ?? now()->startOfWeek())->count(),
            'active_last_24_hours' => (int) (clone $query)->where('last_seen_at', '>=', $scope['day_ago'] ?? now()->subDay())->count(),
        ];
    }

    /**
     * @param  array{user_id?: int, project_id?: int, from?: \DateTimeInterface|string, to?: \DateTimeInterface|string}  $scope
     */
    private function applyScope(Builder $query, array $scope): void
    {
        if (isset($scope['user_id'])) {
            $query->whereKey($scope['user_id']);
        }

        if (isset($scope['project_id'])) {
            $projectId = $scope['project_id'];
            $query->where(function (Builder $query) use ($projectId): void {
                $query->whereHas('ownedProjects', fn (Builder $projects): Builder => $projects->whereKey($projectId))
                    ->orWhereHas('createdTasks', fn (Builder $tasks): Builder => $tasks->where('project_id', $projectId))
                    ->orWhereHas('assignedTasks', fn (Builder $tasks): Builder => $tasks->where('project_id', $projectId));
            });
        }

        if (isset($scope['from'])) {
            $query->where('created_at', '>=', $scope['from']);
        }

        if (isset($scope['to'])) {
            $query->where('created_at', '<=', $scope['to']);
        }
    }
}
