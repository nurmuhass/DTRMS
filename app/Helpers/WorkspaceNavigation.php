<?php
declare(strict_types=1);

namespace Dtrms\Helpers;

final class WorkspaceNavigation
{
    public const PAGES = [
        'staff' => ['/staff', 'staff_workspace', 'Staff dashboard', 'staff/'],
        'supervisor' => ['/supervisor', 'supervisor_workspace', 'Supervisor dashboard', 'supervisor/'],
        'hr_officer' => ['/hr-officer', 'hr_review_reports', 'HR reporting dashboard', 'hr-review/'],
        'hr_manager' => ['/hr-manager', 'hr_manage_organization', 'Organization management', 'hr/'],
        'system_admin' => ['/administration', 'technical_workspace', 'System administration', 'admin/'],
    ];
}
