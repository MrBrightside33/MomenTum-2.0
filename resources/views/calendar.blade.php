@extends('layouts.app')

@section('title', 'Calendar · MomenTum')
@section('skeleton', 'calendar')

@section('content')
    <div
        data-calendar-root
        data-today="{{ $today }}"
        data-timezone="{{ $timezone }}"
        data-calendar-store-url="{{ route('calendar.events.store') }}"
        data-calendar-delete-url-template="{{ route('calendar.events.destroy', ['calendarEvent' => '__event_id__']) }}"
        class="space-y-6"
    >
        <script type="application/json" data-calendar-tasks>@json($calendarTasks)</script>
        <script type="application/json" data-calendar-schedules>@json($scheduleEvents)</script>

        {{-- Calendar heading and quick actions --}}
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p data-calendar-today-label class="text-sm font-semibold uppercase tracking-wide text-indigo-600">{{ $todayLabel }}</p>
                <h1 class="mt-1 text-4xl font-bold tracking-tight text-slate-900">Calendar</h1>
                <p class="mt-2 text-slate-500">Plan your schedule and stay ahead of every deadline.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    data-calendar-enable-notifications
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                >
                    <x-icon name="bell" class="size-4" />
                    Enable deadline alerts
                </button>
                <button
                    type="button"
                    data-calendar-today
                    class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                >
                    Today
                </button>
                <button
                    type="button"
                    data-calendar-focus-form
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                >
                    <x-icon name="plus" class="size-4" />
                    Add schedule
                </button>
            </div>
        </section>

        {{-- Deadline reminders and optional browser notifications --}}
        <section
            data-calendar-deadline-alert
            aria-labelledby="calendar-deadline-title"
            aria-live="polite"
            class="{{ $deadlineAlerts->isEmpty() ? 'hidden' : '' }} rounded-2xl border border-amber-200 bg-amber-50/80 p-4 sm:p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <x-icon name="bell" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="calendar-deadline-title" data-calendar-alert-title class="font-semibold text-amber-950">Deadline check-in</h2>
                        <p data-calendar-alert-summary class="mt-1 text-sm leading-5 text-amber-900">
                            @if ($overdueCount > 0)
                                {{ $overdueCount }} overdue deadline{{ $overdueCount === 1 ? '' : 's' }}
                            @endif
                            @if ($dueTodayCount > 0)
                                {{ $overdueCount > 0 ? ' · ' : '' }}{{ $dueTodayCount }} deadline{{ $dueTodayCount === 1 ? '' : 's' }} due today
                            @endif
                            @if ($dueTomorrowCount > 0)
                                {{ $dueTodayCount > 0 || $overdueCount > 0 ? ' · ' : '' }}{{ $dueTomorrowCount }} more due tomorrow
                            @endif
                            @if ($dueTodayCount === 0 && $dueTomorrowCount === 0 && $overdueCount === 0)
                                Some unfinished deadlines are overdue. Review them and plan your catch-up.
                            @else
                                . Review the tasks below and plan your next steps.
                            @endif
                        </p>
                        <ul data-calendar-alert-list class="mt-3 flex flex-wrap gap-2">
                            @foreach ($deadlineAlerts as $deadline)
                                <li>
                                    <a
                                        href="{{ $deadline['url'] }}"
                                        class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-white/80 px-3 py-2 text-xs font-medium text-amber-950 transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-amber-600/40"
                                    >
                                        <span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                        <span>{{ $deadline['title'] }}</span>
                                        <span data-calendar-alert-due-label class="font-semibold text-amber-700">{{ $deadline['due_label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

            </div>
            <p data-calendar-alert-note class="mt-3 pl-[52px] text-xs text-amber-800">Browser alerts require permission and work while this calendar is open. Reminders stay visible here either way.</p>
        </section>

        {{-- Calendar, selected-day agenda, and scheduling tools --}}
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
            <div class="min-w-0 space-y-6">
                {{-- Month navigation and calendar filters --}}
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div class="flex items-center gap-3">
                            <h2 data-calendar-month class="min-w-[150px] text-lg font-bold text-slate-900">{{ $monthLabel }}</h2>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    data-calendar-month-shift="-1"
                                    aria-label="Previous month"
                                    class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                >
                                    <x-icon name="chevron-left" class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    data-calendar-month-shift="1"
                                    aria-label="Next month"
                                    class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                >
                                    <x-icon name="chevron-right" class="size-4" />
                                </button>
                            </div>
                        </div>

                        <div class="flex w-full items-center gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm sm:w-auto" role="group" aria-label="Filter calendar events">
                            @foreach (['all' => 'All', 'deadline' => 'Deadlines', 'schedule' => 'Schedules'] as $filter => $label)
                                <button
                                    type="button"
                                    data-calendar-filter="{{ $filter }}"
                                    aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}"
                                    class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-sm font-medium transition {{ $filter === 'all' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900' }}"
                                >
                                    {{ $label }}
                                    <span data-calendar-filter-count="{{ $filter }}" class="rounded-full bg-slate-200/70 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-500">
                                        {{ $filter === 'deadline' ? $calendarTasks->count() : ($filter === 'all' ? $calendarTasks->count() : 0) }}
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Month grid --}}
                    <div class="p-2 sm:p-4">
                        <div class="grid grid-cols-7 border-b border-slate-100 pb-2 text-center text-[10px] font-bold uppercase tracking-wide text-slate-400 sm:text-xs">
                            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                                <span>{{ $weekday }}</span>
                            @endforeach
                        </div>
                        <div
                            data-calendar-grid
                            aria-label="Calendar days"
                            class="mt-2 grid grid-cols-7 gap-px overflow-hidden rounded-lg bg-slate-100"
                        >
                            <p class="col-span-7 bg-white p-8 text-center text-sm text-slate-500">Loading calendar…</p>
                        </div>
                    </div>
                </section>

                {{-- Selected day agenda --}}
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 p-4 sm:p-5">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Day agenda</p>
                            <h2 data-calendar-selected-date class="mt-1 text-lg font-bold text-slate-900">{{ $todayLabel }}</h2>
                        </div>
                        <span data-calendar-day-count class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">0 events</span>
                    </div>
                    <div data-calendar-day-events class="divide-y divide-slate-100">
                        <p class="p-5 text-sm text-slate-500">Select a date to review its deadlines and schedules.</p>
                    </div>
                </section>
            </div>

            {{-- Deadline summary, upcoming tasks, and add-schedule form --}}
            <aside class="space-y-6">
                {{-- Deadline summary --}}
                <section class="grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-medium text-slate-500">Due today</p>
                        <p data-calendar-due-today-count class="mt-2 text-2xl font-bold text-slate-900">{{ $dueTodayCount }}</p>
                        <p class="mt-1 text-xs text-slate-500">unfinished tasks</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-medium text-slate-500">Next 7 days</p>
                        <p data-calendar-upcoming-count class="mt-2 text-2xl font-bold text-indigo-600">{{ $upcomingDeadlines->count() }}</p>
                        <p class="mt-1 text-xs text-slate-500">deadlines to plan</p>
                    </div>
                </section>

                {{-- Upcoming deadline list --}}
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 p-4">
                        <h2 class="font-bold text-slate-900">Upcoming deadlines</h2>
                        <p class="mt-1 text-xs text-slate-500">Your next tasks that need attention</p>
                    </div>
                    <div data-calendar-upcoming-list class="divide-y divide-slate-100">
                        @forelse ($upcomingDeadlines as $deadline)
                            <a href="{{ $deadline['url'] }}" class="flex items-start gap-3 p-4 transition hover:bg-slate-50">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $deadline['priority'] === 'High' ? 'bg-red-500' : 'bg-indigo-500' }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-slate-800">{{ $deadline['title'] }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">{{ $deadline['due_label'] }} · {{ $deadline['category'] }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="p-4 text-sm text-slate-500">No upcoming deadlines. You’re all caught up.</p>
                        @endforelse
                    </div>
                </section>

                {{-- Add a schedule --}}
                <section id="calendar-schedule-form" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <x-icon name="calendar" class="size-[18px]" />
                        </span>
                        <div>
                            <h2 class="font-bold text-slate-900">Add a schedule</h2>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Add a personal event to your calendar.</p>
                        </div>
                    </div>

                    <form data-calendar-schedule-form method="POST" action="{{ route('calendar.events.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="calendar-schedule-title" class="text-sm font-semibold text-slate-700">Schedule title</label>
                            <input
                                id="calendar-schedule-title"
                                name="title"
                                type="text"
                                maxlength="100"
                                placeholder="e.g. Design review"
                                class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                            >
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="calendar-schedule-date" class="text-sm font-semibold text-slate-700">Date</label>
                                <input
                                    id="calendar-schedule-date"
                                    name="date"
                                    type="date"
                                    min="{{ $today }}"
                                    value="{{ $today }}"
                                    class="mt-1.5 h-10 w-full min-w-0 rounded-xl border border-slate-200 bg-white px-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                            </div>
                            <div>
                                <label for="calendar-schedule-time" class="text-sm font-semibold text-slate-700">Time <span class="font-normal text-slate-400">optional</span></label>
                                <input
                                    id="calendar-schedule-time"
                                    name="time"
                                    type="time"
                                    class="mt-1.5 h-10 w-full min-w-0 rounded-xl border border-slate-200 bg-white px-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                            </div>
                        </div>
                        <button
                            type="submit"
                            class="flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                        >
                            <x-icon name="plus" class="size-4" />
                            Save schedule
                        </button>
                        <p data-calendar-status role="status" aria-live="polite" class="min-h-5 text-xs text-slate-500"></p>
                    </form>
                </section>
            </aside>
        </div>
    </div>
@endsection
