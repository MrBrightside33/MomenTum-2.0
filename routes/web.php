<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReportExportController as AdminReportExportController;
use App\Http\Controllers\Admin\SearchController as AdminSearchController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if ($user !== null && $user->defaultPageRoute() !== 'home') {
        return redirect()->route($user->defaultPageRoute());
    }

    return view('landing');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.submit');

    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('register.submit');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth', 'auth.session', EnsureActiveUser::class])
    ->name('logout');

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{task}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::get('/team', [TeamController::class, 'index'])->name('team');

    Route::get('/calendar', [CalendarEventController::class, 'index'])->name('calendar');
    Route::post('/calendar/events', [CalendarEventController::class, 'store'])->name('calendar.events.store');
    Route::delete('/calendar/events/{calendarEvent}', [CalendarEventController::class, 'destroy'])
        ->name('calendar.events.destroy');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
    Route::patch('/inbox/read-all', [InboxController::class, 'markAllRead'])->name('inbox.read-all');
    Route::patch('/inbox/{notification}/read', [InboxController::class, 'toggleRead'])->name('inbox.read');
    Route::patch('/inbox/{notification}/archive', [InboxController::class, 'archive'])->name('inbox.archive');
    Route::patch('/inbox/{notification}/restore', [InboxController::class, 'restore'])->name('inbox.restore');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password.update');
    Route::put('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences.update');
    Route::put('/settings/appearance', [SettingsController::class, 'updateAppearance'])->name('settings.appearance.update');

    Route::get('/tasks/search-suggestions', [TaskController::class, 'searchSuggestions'])
        ->name('tasks.search-suggestions');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/progress', [TaskController::class, 'progress'])->name('tasks.progress');
    Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])
        ->whereNumber('task')
        ->name('tasks.restore');
    Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
});

Route::middleware(['auth', 'auth.session', 'admin'])->group(function (): void {
    Route::get('/admin', AdminOverviewController::class)->name('admin.overview');
    Route::get('/admin/overview/data', [AdminOverviewController::class, 'data'])->name('admin.overview.data');
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    Route::get('/admin/tasks', [AdminTaskController::class, 'index'])->name('admin.tasks.index');
    Route::get('/admin/projects', [AdminProjectController::class, 'index'])->name('admin.projects.index');
    Route::get('/admin/reports', [AdminReportController::class, 'index'])->name('admin.reports.index');
    Route::get('/admin/reports/data', [AdminReportController::class, 'data'])->name('admin.reports.data');
    Route::get('/admin/reports/export/{dataset}', [AdminReportExportController::class, 'download'])
        ->name('admin.reports.export');
    Route::get('/admin/audit-log', [AdminAuditLogController::class, 'index'])->name('admin.audit.index');
    Route::get('/admin/search', AdminSearchController::class)->name('admin.search');
    Route::get('/admin/settings', [AdminSettingsController::class, 'index'])->name('admin.settings.index');
});
