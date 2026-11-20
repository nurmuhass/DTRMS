<?php
declare(strict_types=1);

use Dtrms\Controllers\AdminController;
use Dtrms\Controllers\AuthController;
use Dtrms\Controllers\HomeController;
use Dtrms\Controllers\HrController;
use Dtrms\Controllers\HrReviewController;
use Dtrms\Controllers\NotificationController;
use Dtrms\Controllers\ReportingController;
use Dtrms\Controllers\StaffController;
use Dtrms\Controllers\StaffScheduleController;
use Dtrms\Controllers\StaffLeaveController;
use Dtrms\Controllers\SubmissionMonitoringController;
use Dtrms\Controllers\StaffRecordsController;
use Dtrms\Controllers\WeeklyScheduleController;
use Dtrms\Controllers\SupervisorController;
use Dtrms\Core\Database;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\Organization;
use Dtrms\Models\AdministrativeRoles;
use Dtrms\Models\SystemConfiguration;
use Dtrms\Models\TechnicalMonitoring;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Notifications;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffReports;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\WorkHolidays;
use Dtrms\Models\UserAccount;
use Dtrms\Models\WeeklySchedules;
use Dtrms\Models\SupervisorReports;
use Dtrms\Services\AccountRecovery;
use Dtrms\Services\Authentication;
use Dtrms\Services\DailyReporting;
use Dtrms\Services\ReportingSummary;
use Dtrms\Services\HrManagement;
use Dtrms\Services\HrApproval;
use Dtrms\Services\SupervisorApproval;
use Dtrms\Services\StaffScheduleManagement;
use Dtrms\Services\StaffLeaveManagement;
use Dtrms\Services\LeaveAttachments;

$database = Database::connect($config['database']);
$systemSettings = new SystemConfiguration($database);
$policy = $systemSettings->configuration();
View::setBranding($systemSettings->branding($policy));
$authentication = new Authentication($database, $browser, $policy['session_idle_minutes']);
$access = new Access($authentication, $browser);
$authController = new AuthController($authentication, $browser, new AccountRecovery($database));
$homeController = new HomeController($browser);

$router->add('GET', '/login', [$authController, 'loginForm']);
$router->add('POST', '/login', $access->csrf([$authController, 'login']));
$router->add('GET', '/forgot-password', [$authController, 'forgot']);
$router->add('GET', '/reset-password', [$authController, 'resetForm']);
$router->add('POST', '/reset-password', $access->csrf([$authController, 'reset']));
$router->add('GET', '/', $access->protected('portal_access', [$homeController, 'index']));
$router->add('GET', '/change-password', $access->protected('password_change', [$authController, 'changeForm'], allowTemporary: true));
$router->add('POST', '/change-password', $access->csrf(
    $access->protected('password_change', [$authController, 'change'], allowTemporary: true)
));
$router->add('POST', '/logout', $access->csrf(
    $access->protected('session_logout', [$authController, 'logout'], allowTemporary: true)
));

$monitoring = new TechnicalMonitoring($database, $policy['session_idle_minutes']);
$admin = new AdminController($systemSettings, new AdministrativeRoles($database), $monitoring, $browser);
$router->add('GET', '/branding/logo', [$admin, 'logo']);
foreach ([
    '/administration' => ['dashboard'],
    '/administration/settings' => ['settings', 'saveSettings'],
    '/administration/settings/logo' => [null, 'saveLogo'],
    '/administration/roles' => ['roles', 'saveRole'],
] as $path => $handlers) {
    if ($handlers[0] !== null) {
        $router->add('GET', $path, $access->protected('technical_workspace', [$admin, $handlers[0]], role: 'system_admin'));
    }
    if (isset($handlers[1])) {
        $router->add('POST', $path, $access->protected('technical_workspace',
            $access->csrf([$admin, $handlers[1]]), role: 'system_admin'));
    }
}
foreach (array_keys(TechnicalMonitoring::SECTIONS) as $section) {
    $router->add('GET', '/administration/' . $section, $access->protected('technical_workspace',
        static fn (array $actor): string => $admin->monitor($actor, $section), role: 'system_admin'));
}

$staffReports = new StaffReports($database);
$analytics = new ReportAnalytics($database);
$workSchedules = new StaffWorkSchedules($database);
$staffLeaves = new StaffLeaves($database);
$weeklyController = new WeeklyScheduleController(new WeeklySchedules($database),$staffLeaves,$browser);
foreach (['/staff/schedule'=>['staff_workspace','staff'], '/hr-manager/weekly-schedules'=>['hr_manage_organization','hr_manager']] as $path=>[$permission,$role]) {
    $router->add('GET',$path,$access->protected($permission,[$weeklyController,'show'],role:$role));
    $router->add('POST',$path,$access->protected($permission,$access->csrf([$weeklyController,'save']),role:$role));
}
$workHolidays = new WorkHolidays($database);
$reporting = new ReportingController($analytics, new ReportingSummary($analytics, $policy, $workSchedules, $staffLeaves, $workHolidays), $staffReports, $systemSettings,
    $monitoring, $browser, $policy, new UserAccount($database));
$router->add('GET', '/reports/analytics', $access->protected('portal_access', [$reporting, 'dashboard']));
$router->add('GET', '/reports/export', $access->protected('portal_access', [$reporting, 'export']));
$router->add('GET', '/administration/export', $access->protected('technical_workspace',
    [$reporting, 'administrative'], role: 'system_admin'));
$staff = new StaffController($staffReports, new DailyReporting($database, $staffReports), $browser,
    array_intersect_key($policy, array_flip(['work_start', 'work_end', 'arrival_grace', 'report_deadline']))
    + ['weekly_start'=>(new WeeklySchedules($database))->start() ?: '9999-12-31']);
foreach ([
    '/staff' => 'dashboard',
    '/staff/reports' => 'history',
    '/staff/reports/edit' => 'form',
    '/staff/reports/view' => 'details',
] as $path => $handler) {
    $router->add('GET', $path, $access->protected('staff_workspace', [$staff, $handler], role: 'staff'));
}
$router->add('POST', '/staff/reports/edit', $access->protected('staff_workspace',
    $access->csrf([$staff, 'save']), role: 'staff'));

$notifications = new Notifications($database);
$hrReviews = new HrReviews($database);
$inbox = new NotificationController($notifications, $hrReviews, $browser);
$router->add('GET', '/notifications', $access->protected('portal_access', [$inbox, 'inbox']));
$router->add('GET', '/notifications/feed', $access->protected('portal_access', [$inbox, 'feed']));
$router->add('POST', '/notifications/read', $access->protected('portal_access', $access->csrf([$inbox, 'read'])));
$router->add('POST', '/notifications/read-all', $access->protected('portal_access', $access->csrf([$inbox, 'readAll'])));

$supervisorReports = new SupervisorReports($database);
$supervisor = new SupervisorController($supervisorReports, $staffReports,
    new SupervisorApproval($database, $supervisorReports, $staffReports, $notifications), $browser);
foreach ([
    '/supervisor' => 'dashboard',
    '/supervisor/reports' => 'queue',
    '/supervisor/report' => 'detail',
    '/supervisor/history' => 'history',
    '/supervisor/approved' => 'approved',
    '/supervisor/staff' => 'staff',
] as $path => $handler) {
    $router->add('GET', $path, $access->protected('supervisor_workspace', [$supervisor, $handler], role: 'supervisor'));
}
$router->add('POST', '/supervisor/report', $access->protected('supervisor_workspace',
    $access->csrf([$supervisor, 'decide']), role: 'supervisor'));

$hrReview = new HrReviewController($hrReviews, $staffReports, new HrApproval($database, $hrReviews, $notifications), $browser);
$router->add('GET', '/hr-officer', $access->protected(HrReviews::READ, [$hrReview, 'dashboard'], role: 'hr_officer'));
foreach ([
    '/hr' => 'dashboard',
    '/hr/reports' => 'queue',
    '/hr/reports/approved' => 'approved',
    '/hr/reports/returned' => 'returned',
    '/hr/reports/view' => 'detail',
] as $path => $handler) {
    $router->add('GET', $path, $access->protected(HrReviews::READ, [$hrReview, $handler], role: HrReviews::ROLES));
}
$router->add('POST', '/hr/reports/view', $access->protected(HrReviews::DECIDE,
    $access->csrf([$hrReview, 'decide']), role: HrReviews::ROLES));

$organization = new Organization($database);
$submissionMonitoring = new SubmissionMonitoring($database, $workSchedules, $policy['report_deadline']);
$staffRecords = new StaffRecordsController($submissionMonitoring, $workSchedules, $staffLeaves,
    new UserAccount($database), new WeeklySchedules($database), $browser, $policy['organization_name'] ?: $policy['app_name']);
$router->add('GET', StaffRecordsController::PATH, $access->protected('portal_access', [$staffRecords, 'profile']));
$router->add('POST', StaffRecordsController::PATH . '/export', $access->protected('portal_access', $access->csrf([$staffRecords, 'export'])));
$router->add('GET', SubmissionMonitoringController::PATH . '/staff', $access->protected(SubmissionMonitoringController::PERMISSION,
    [$staffRecords, 'profile'], role: SubmissionMonitoringController::ROLE));
$monitoringController = new SubmissionMonitoringController($submissionMonitoring,
    $organization, $staffReports, $workHolidays, $staffLeaves, $browser, new UserAccount($database));
foreach (['' => 'index', '/report' => 'report', '/holidays' => 'holidayList'] as $suffix => $handler) {
    $router->add('GET', SubmissionMonitoringController::PATH . $suffix, $access->protected(SubmissionMonitoringController::PERMISSION,
        [$monitoringController, $handler], role: SubmissionMonitoringController::ROLE));
}
$router->add('POST', SubmissionMonitoringController::PATH . '/export', $access->protected(SubmissionMonitoringController::PERMISSION,
    $access->csrf([$monitoringController, 'export']), role: SubmissionMonitoringController::ROLE));
$router->add('POST', SubmissionMonitoringController::PATH . '/holidays', $access->protected(SubmissionMonitoringController::PERMISSION,
    $access->csrf([$monitoringController, 'holidayChange']), role: SubmissionMonitoringController::ROLE));
$leaveAttachments = new LeaveAttachments($config['leave_storage']);
$leaveController = new StaffLeaveController($organization, $staffLeaves,
    new StaffLeaveManagement($database, $staffLeaves, $leaveAttachments), $leaveAttachments, $browser);
foreach ([
    '/hr-manager/leaves' => ['index'],
    '/hr-manager/leaves/edit' => ['form', 'save'],
    '/hr-manager/leaves/view' => ['detail', 'decide'],
    '/hr-manager/leaves/document' => ['download'],
] as $path => $handlers) {
    $router->add('GET', $path, $access->protected(StaffLeaveManagement::PERMISSION, [$leaveController, $handlers[0]], role: StaffLeaveManagement::ROLE));
    if (isset($handlers[1])) {
        $router->add('POST', $path, $access->protected(StaffLeaveManagement::PERMISSION,
            $access->csrf([$leaveController, $handlers[1]]), role: StaffLeaveManagement::ROLE));
    }
}
$scheduleController = new StaffScheduleController($organization, $workSchedules,
    new StaffScheduleManagement($database, $workSchedules), $browser);
$router->add('GET', '/hr-manager/schedules', $access->protected('hr_manage_organization',
    [$scheduleController, 'index'], role: 'hr_manager'));
$router->add('POST', '/hr-manager/schedules', $access->protected('hr_manage_organization',
    $access->csrf([$scheduleController, 'save']), role: 'hr_manager'));
$hr = new HrController($organization, new HrManagement($database, $organization), $browser);
foreach ([
    '/hr-manager' => ['dashboard'],
    '/hr-manager/departments' => ['departments'],
    '/hr-manager/departments/edit' => ['departmentForm', 'saveDepartment'],
    '/hr-manager/departments/status' => [null, 'departmentStatus'],
    '/hr-manager/staff' => ['directory'],
    '/hr-manager/staff/edit' => ['userForm', 'saveUser'],
    '/hr-manager/staff/access' => ['account', 'saveAccount'],
    '/hr-manager/staff/supervisor' => ['assignment', 'saveAssignment'],
    '/hr-manager/staff/import' => ['import', 'upload'],
    '/hr-manager/staff/template' => ['template'],
] as $path => $handlers) {
    if ($handlers[0] !== null) {
        $router->add('GET', $path, $access->protected('hr_manage_organization', [$hr, $handlers[0]], role: 'hr_manager'));
    }
    if (isset($handlers[1])) {
        $router->add('POST', $path, $access->protected('hr_manage_organization',
            $access->csrf([$hr, $handlers[1]]), role: 'hr_manager'));
    }
}
