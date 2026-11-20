<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dtrms\Middleware\Access;
use Dtrms\Models\HrReviews;

final class ReportingAccess
{
    public const HR_EXPORT = 'hr_export_reports';

    public static function scope(array $actor): ?string
    {
        return match (true) {
            Access::permits($actor, 'staff_workspace', 'staff') => 'staff',
            Access::permits($actor, 'supervisor_workspace', 'supervisor') => 'supervisor',
            Access::permits($actor, HrReviews::READ, HrReviews::ROLES) => 'organization',
            default => null,
        };
    }

    public static function exports(array $actor): bool
    {
        $scope = self::scope($actor);
        return $scope !== null && ($scope !== 'organization' || Access::permits($actor, self::HR_EXPORT, HrReviews::ROLES));
    }
}
