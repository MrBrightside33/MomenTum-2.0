import { request } from './http';

const initializeCalendar = () => {
    const root = document.querySelector('[data-calendar-root]');

    if (!root) {
        return;
    }

    const taskData = root.querySelector('[data-calendar-tasks]');
    const scheduleData = root.querySelector('[data-calendar-schedules]');
    const calendarGrid = root.querySelector('[data-calendar-grid]');
    const monthLabel = root.querySelector('[data-calendar-month]');
    const todayLabel = root.querySelector('[data-calendar-today-label]');
    const selectedDateLabel = root.querySelector('[data-calendar-selected-date]');
    const dayCount = root.querySelector('[data-calendar-day-count]');
    const dayEventsContainer = root.querySelector('[data-calendar-day-events]');
    const upcomingList = root.querySelector('[data-calendar-upcoming-list]');
    const alertPanel = root.querySelector('[data-calendar-deadline-alert]');
    const alertSummary = root.querySelector('[data-calendar-alert-summary]');
    const alertList = root.querySelector('[data-calendar-alert-list]');
    const dueTodayCount = root.querySelector('[data-calendar-due-today-count]');
    const upcomingCount = root.querySelector('[data-calendar-upcoming-count]');
    const scheduleForm = root.querySelector('[data-calendar-schedule-form]');
    const scheduleSubmitButton = scheduleForm?.querySelector('button[type="submit"]');
    const scheduleDateInput = scheduleForm?.elements.namedItem('date');
    const scheduleTitleInput = scheduleForm?.elements.namedItem('title');
    const statusMessage = root.querySelector('[data-calendar-status]');
    const notificationButton = root.querySelector('[data-calendar-enable-notifications]');
    const filterButtons = Array.from(root.querySelectorAll('[data-calendar-filter]'));
    const today = root.dataset.today;
    const timezone = root.dataset.timezone;
    const scheduleDeleteUrlTemplate = root.dataset.calendarDeleteUrlTemplate;
    const notifiedTaskIds = new Set();
    let activeFilter = 'all';
    let selectedDate = today;
    let monthDate;
    let currentToday = today;
    let schedules = [];

    if (!taskData || !scheduleData || !calendarGrid || !today || !timezone) {
        console.error('Calendar initialization is missing required page data.');
        return;
    }

    const taskEvents = JSON.parse(taskData.textContent || '[]');
    const storedSchedules = JSON.parse(scheduleData.textContent || '[]');

    if (!Array.isArray(taskEvents) || !Array.isArray(storedSchedules)) {
        console.error('Calendar data must be arrays.');
        return;
    }

    const announce = (message) => {
        if (statusMessage) {
            statusMessage.textContent = message;
        }
    };

    const parseDate = (dateString) => {
        const [year, month, day] = dateString.split('-').map(Number);

        return new Date(year, month - 1, day);
    };

    monthDate = parseDate(today);
    monthDate.setDate(1);

    const dateKey = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    const todayInTimezone = () => {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date());
        const dateParts = Object.fromEntries(parts.map(({type, value}) => [type, value]));

        return `${dateParts.year}-${dateParts.month}-${dateParts.day}`;
    };

    const dateOrdinal = (dateString) => {
        const [year, month, day] = dateString.split('-').map(Number);

        return Date.UTC(year, month - 1, day) / 86400000;
    };

    const daysUntil = (dateString) => dateOrdinal(dateString) - dateOrdinal(currentToday);
    const dueLabelFor = (event) => {
        const remainingDays = daysUntil(event.date);

        if (remainingDays < 0) {
            return `Overdue by ${Math.abs(remainingDays)} day${Math.abs(remainingDays) === 1 ? '' : 's'}`;
        }

        if (remainingDays === 0) {
            return 'Due today';
        }

        if (remainingDays === 1) {
            return 'Due tomorrow';
        }

        return `Due in ${remainingDays} days`;
    };
    const formatDate = (dateString, options) => new Intl.DateTimeFormat(undefined, options).format(parseDate(dateString));
    const isValidSchedule = (schedule) => schedule
        && typeof schedule.id === 'string'
        && typeof schedule.title === 'string'
        && typeof schedule.date === 'string'
        && /^\d{4}-\d{2}-\d{2}$/.test(schedule.date)
        && dateKey(parseDate(schedule.date)) === schedule.date
        && typeof schedule.time === 'string'
        && (schedule.time === '' || /^\d{2}:\d{2}$/.test(schedule.time));

    if (!storedSchedules.every(isValidSchedule)) {
        console.error('Calendar schedule data has an invalid format.');
        return;
    }

    const getAllEvents = () => [
        ...taskEvents.map((event) => ({...event, kind: 'deadline'})),
        ...schedules.map((event) => ({...event, kind: 'schedule'})),
    ];

    const getVisibleEvents = () => getAllEvents().filter((event) => activeFilter === 'all' || event.kind === activeFilter);

    const updateFilterButtons = () => {
        filterButtons.forEach((button) => {
            const isActive = button.dataset.calendarFilter === activeFilter;

            button.setAttribute('aria-pressed', String(isActive));
            button.classList.toggle('bg-white', isActive);
            button.classList.toggle('text-slate-900', isActive);
            button.classList.toggle('shadow-sm', isActive);
            button.classList.toggle('text-slate-500', !isActive);
        });

        const counts = {
            all: taskEvents.length + schedules.length,
            deadline: taskEvents.length,
            schedule: schedules.length,
        };

        Object.entries(counts).forEach(([filter, count]) => {
            const countElement = root.querySelector(`[data-calendar-filter-count="${filter}"]`);

            if (countElement) {
                countElement.textContent = String(count);
            }
        });
    };

    const createEventMarker = (event) => {
        const marker = document.createElement('span');
        const markerClass = event.kind === 'schedule'
            ? 'bg-violet-100 text-violet-700'
            : event.completed
                ? 'bg-emerald-100 text-emerald-700'
                : event.priority === 'High'
                    ? 'bg-red-50 text-red-700'
                    : 'bg-indigo-50 text-indigo-700';

        marker.className = `hidden max-w-full truncate rounded px-1 py-0.5 text-left text-[10px] font-medium sm:block ${markerClass}`;
        marker.textContent = event.title;

        return marker;
    };

    const createDayCell = (date, events) => {
        const key = dateKey(date);
        const isCurrentMonth = date.getMonth() === monthDate.getMonth();
        const isToday = key === currentToday;
        const isSelected = key === selectedDate;
        const cell = document.createElement('button');
        const dayNumber = document.createElement('span');
        const markers = document.createElement('span');

        cell.type = 'button';
        cell.dataset.calendarDay = key;
        const eventTitles = events
            .map((event) => event.completed ? `Completed: ${event.title}` : event.title)
            .join(', ');
        const eventDescription = eventTitles ? `: ${eventTitles}` : '';

        cell.setAttribute('aria-label', `${formatDate(key, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})}, ${events.length} ${events.length === 1 ? 'event' : 'events'}${eventDescription}`);
        cell.setAttribute('aria-pressed', String(isSelected));
        cell.className = 'flex min-h-[72px] flex-col items-center border bg-white p-1 text-left transition hover:bg-indigo-50/60 focus:z-10 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 sm:min-h-24 sm:items-stretch sm:p-2';
        cell.classList.toggle('bg-slate-50/70', !isCurrentMonth);
        cell.classList.toggle('text-slate-400', !isCurrentMonth);
        cell.classList.toggle('border-indigo-500', isSelected);
        cell.classList.toggle('border-transparent', !isSelected);

        if (isToday) {
            cell.setAttribute('aria-current', 'date');
        }

        dayNumber.className = `flex size-7 items-center justify-center rounded-full text-xs font-semibold ${isToday ? 'bg-indigo-600 text-white' : isCurrentMonth ? 'text-slate-700' : 'text-slate-400'}`;
        dayNumber.textContent = String(date.getDate());
        cell.append(dayNumber);

        markers.className = 'mt-1 flex w-full flex-col gap-0.5';
        events.slice(0, 2).forEach((event) => {
            const dot = document.createElement('span');

            dot.className = `size-1.5 rounded-full sm:hidden ${event.kind === 'schedule' ? 'bg-violet-500' : event.completed ? 'bg-emerald-500' : event.priority === 'High' ? 'bg-red-500' : 'bg-indigo-500'}`;
            dot.setAttribute('aria-hidden', 'true');
            markers.append(dot, createEventMarker(event));
        });

        if (events.length > 2) {
            const more = document.createElement('span');

            more.className = 'hidden text-[10px] font-medium text-slate-500 sm:block';
            more.textContent = `+${events.length - 2} more`;
            markers.append(more);
        }

        cell.append(markers);

        return cell;
    };

    const renderCalendar = () => {
        const firstOfMonth = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
        const firstCellOffset = (firstOfMonth.getDay() + 6) % 7;
        const gridStart = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1 - firstCellOffset);
        const eventsByDate = new Map();

        getVisibleEvents().forEach((event) => {
            const eventsForDate = eventsByDate.get(event.date) ?? [];

            eventsForDate.push(event);
            eventsByDate.set(event.date, eventsForDate);
        });

        monthLabel.textContent = new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(monthDate);
        updateFilterButtons();
        calendarGrid.replaceChildren();

        for (let dayOffset = 0; dayOffset < 42; dayOffset += 1) {
            const date = new Date(gridStart);

            date.setDate(gridStart.getDate() + dayOffset);
            const key = dateKey(date);
            const events = eventsByDate.get(key) ?? [];

            calendarGrid.append(createDayCell(date, events));
        }

        renderSelectedDay();
    };

    const renderSelectedDay = () => {
        const events = getVisibleEvents()
            .filter((event) => event.date === selectedDate)
            .sort((first, second) => (first.time || '').localeCompare(second.time || ''));

        selectedDateLabel.textContent = formatDate(selectedDate, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'});
        dayCount.textContent = `${events.length} ${events.length === 1 ? 'event' : 'events'}`;
        dayEventsContainer.replaceChildren();

        if (events.length === 0) {
            const emptyMessage = document.createElement('p');

            emptyMessage.className = 'p-5 text-sm text-slate-500';
            emptyMessage.textContent = activeFilter === 'deadline'
                ? 'No task deadlines on this date.'
                : activeFilter === 'schedule'
                    ? 'No schedules on this date.'
                    : 'No deadlines or schedules on this date.';
            dayEventsContainer.append(emptyMessage);

            return;
        }

        events.forEach((event) => {
            const row = document.createElement('div');
            const indicator = document.createElement('span');
            const content = document.createElement('span');
            const title = document.createElement('span');
            const detail = document.createElement('span');
            const description = document.createElement('span');
            const eventTitle = event.kind === 'deadline'
                ? (event.completed ? 'Completed' : dueLabelFor(event))
                : (event.time ? formatTime(event.time) : 'All day');

            row.className = 'flex items-start gap-3 p-4';
            indicator.className = `mt-1.5 size-2.5 shrink-0 rounded-full ${event.kind === 'schedule' ? 'bg-violet-500' : event.completed ? 'bg-emerald-500' : event.priority === 'High' ? 'bg-red-500' : 'bg-indigo-500'}`;
            indicator.setAttribute('aria-hidden', 'true');
            content.className = 'min-w-0 flex-1';
            title.className = `block text-sm font-semibold ${event.completed ? 'text-slate-500 line-through' : 'text-slate-900'}`;
            title.textContent = event.title;
            detail.className = 'mt-1 block text-xs text-slate-500';
            detail.textContent = event.kind === 'deadline' ? `${eventTitle} · ${event.category}` : eventTitle;
            content.append(title, detail);

            if (event.kind === 'schedule') {
                description.className = 'mt-1 block text-xs font-medium text-violet-700';
                description.textContent = 'Personal schedule';
                content.append(description);

                const deleteButton = document.createElement('button');

                deleteButton.type = 'button';
                deleteButton.dataset.calendarDeleteSchedule = event.id;
                deleteButton.className = 'shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500/40';
                deleteButton.setAttribute('aria-label', `Delete schedule: ${event.title}`);
                deleteButton.textContent = 'Remove';
                row.append(indicator, content, deleteButton);
            } else if (event.kind === 'deadline') {
                const link = document.createElement('a');

                link.href = event.url;
                link.className = 'shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40';
                link.textContent = 'View task';
                row.append(indicator, content, link);
            } else {
                row.append(indicator, content);
            }

            dayEventsContainer.append(row);
        });
    };

    const renderUpcomingDeadlines = () => {
        const upcoming = taskEvents
            .filter((event) => !event.completed && daysUntil(event.date) >= 0 && daysUntil(event.date) <= 7)
            .sort((first, second) => first.date.localeCompare(second.date));

        upcomingCount.textContent = String(upcoming.length);
        upcomingList.replaceChildren();

        if (upcoming.length === 0) {
            const emptyMessage = document.createElement('p');

            emptyMessage.className = 'p-4 text-sm text-slate-500';
            emptyMessage.textContent = 'No upcoming deadlines. You’re all caught up.';
            upcomingList.append(emptyMessage);

            return;
        }

        upcoming.forEach((event) => {
            const link = document.createElement('a');
            const indicator = document.createElement('span');
            const text = document.createElement('span');
            const title = document.createElement('span');
            const detail = document.createElement('span');

            link.href = event.url;
            link.className = 'flex items-start gap-3 p-4 transition hover:bg-slate-50';
            indicator.className = `mt-1.5 size-2 shrink-0 rounded-full ${event.priority === 'High' ? 'bg-red-500' : 'bg-indigo-500'}`;
            indicator.setAttribute('aria-hidden', 'true');
            text.className = 'min-w-0 flex-1';
            title.className = 'block truncate text-sm font-semibold text-slate-800';
            title.textContent = event.title;
            detail.className = 'mt-1 block text-xs text-slate-500';
            detail.textContent = `${dueLabelFor(event)} · ${event.category}`;
            text.append(title, detail);
            link.append(indicator, text);
            upcomingList.append(link);
        });
    };

    const renderDeadlineAlerts = () => {
        const alerts = taskEvents
            .filter((event) => !event.completed && daysUntil(event.date) <= 1)
            .sort((first, second) => first.date.localeCompare(second.date));
        const dueToday = alerts.filter((event) => daysUntil(event.date) === 0).length;
        const dueTomorrow = alerts.filter((event) => daysUntil(event.date) === 1).length;
        const overdue = alerts.filter((event) => daysUntil(event.date) < 0).length;
        const summaryParts = [];

        if (overdue > 0) {
            summaryParts.push(`${overdue} overdue deadline${overdue === 1 ? '' : 's'}`);
        }

        if (dueToday > 0) {
            summaryParts.push(`${dueToday} deadline${dueToday === 1 ? '' : 's'} due today`);
        }

        if (dueTomorrow > 0) {
            summaryParts.push(`${dueTomorrow} more due tomorrow`);
        }

        alertPanel.classList.toggle('hidden', alerts.length === 0);
        dueTodayCount.textContent = String(dueToday);
        alertSummary.textContent = alerts.length > 0
            ? `${summaryParts.join(' · ')}. Review the tasks below and plan your next steps.`
            : 'No deadlines are closing soon.';
        alertList.replaceChildren();

        alerts.forEach((event) => {
            const item = document.createElement('li');
            const link = document.createElement('a');
            const dot = document.createElement('span');
            const title = document.createElement('span');
            const dueLabel = document.createElement('span');

            link.href = event.url;
            link.className = 'inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-white/80 px-3 py-2 text-xs font-medium text-amber-950 transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-amber-600/40';
            dot.className = 'size-1.5 rounded-full bg-amber-500';
            dot.setAttribute('aria-hidden', 'true');
            title.textContent = event.title;
            dueLabel.dataset.calendarAlertDueLabel = '';
            dueLabel.className = 'font-semibold text-amber-700';
            dueLabel.textContent = dueLabelFor(event);
            link.append(dot, title, dueLabel);
            item.append(link);
            alertList.append(item);
        });
    };

    const formatTime = (time) => {
        const [hours, minutes] = time.split(':').map(Number);
        const date = new Date();

        date.setHours(hours, minutes, 0, 0);

        return new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(date);
    };

    const notifyUpcomingDeadlines = () => {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        taskEvents
            .filter((event) => !event.completed && daysUntil(event.date) <= 1 && !notifiedTaskIds.has(event.id))
            .forEach((event) => {
                try {
                    new Notification('MomenTum deadline reminder', {
                        body: `${event.title} — ${dueLabelFor(event)}. Open your calendar to plan a catch-up.`,
                        tag: `deadline-${event.id}`,
                    });
                    notifiedTaskIds.add(event.id);
                } catch (error) {
                    console.error('Unable to show a browser deadline notification.', error);
                    announce('A browser alert could not be shown. The deadline is still listed in your calendar.');
                }
            });
    };

    const updateNotificationButton = () => {
        if (!notificationButton) {
            return;
        }

        if (!('Notification' in window)) {
            notificationButton.disabled = true;
            notificationButton.textContent = 'Browser alerts unavailable';

            return;
        }

        if (Notification.permission === 'granted') {
            notificationButton.textContent = 'Browser alerts enabled';
            notifyUpcomingDeadlines();
        } else if (Notification.permission === 'denied') {
            notificationButton.textContent = 'Browser alerts blocked';
        }
    };

    const updateMonth = (amount) => {
        monthDate.setMonth(monthDate.getMonth() + amount);
        const selectedDay = parseDate(selectedDate).getDate();
        const lastDayOfMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();

        selectedDate = dateKey(new Date(monthDate.getFullYear(), monthDate.getMonth(), Math.min(selectedDay, lastDayOfMonth)));
        scheduleDateInput.value = selectedDate < currentToday ? currentToday : selectedDate;
        renderCalendar();
    };

    schedules = storedSchedules;
    updateNotificationButton();
    renderCalendar();
    renderUpcomingDeadlines();
    renderDeadlineAlerts();
    window.setInterval(() => {
        const newToday = todayInTimezone();

        if (newToday === currentToday) {
            return;
        }

        const previousToday = currentToday;

        currentToday = newToday;
        todayLabel.textContent = formatDate(currentToday, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'});
        if (selectedDate === previousToday) {
            selectedDate = newToday;
            monthDate = parseDate(newToday);
            monthDate.setDate(1);
        }
        scheduleDateInput.min = currentToday;
        scheduleDateInput.value = selectedDate < currentToday ? currentToday : selectedDate;
        renderCalendar();
        renderUpcomingDeadlines();
        renderDeadlineAlerts();
        notifyUpcomingDeadlines();
    }, 60_000);

    root.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const monthButton = event.target.closest('[data-calendar-month-shift]');
        const dayButton = event.target.closest('[data-calendar-day]');
        const filterButton = event.target.closest('[data-calendar-filter]');
        const deleteScheduleButton = event.target.closest('[data-calendar-delete-schedule]');

        if (deleteScheduleButton instanceof HTMLButtonElement) {
            const scheduleId = deleteScheduleButton.dataset.calendarDeleteSchedule;
            const scheduleIndex = schedules.findIndex((schedule) => schedule.id === scheduleId);

            if (scheduleIndex === -1) {
                announce('That schedule could not be found.');

                return;
            }

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const deleteUrl = scheduleDeleteUrlTemplate?.replace('__event_id__', encodeURIComponent(scheduleId));

            if (!csrfToken || !deleteUrl) {
                announce('Schedule could not be removed. Refresh the page and try again.');

                return;
            }

            deleteScheduleButton.disabled = true;

            try {
                const { data: result } = await request(deleteUrl, {
                    method: 'DELETE',
                });

                if (String(result.id) !== scheduleId) {
                    announce(result.message ?? 'Schedule could not be removed. Please try again.');
                    return;
                }

                const [removedSchedule] = schedules.splice(scheduleIndex, 1);
                announce(`${removedSchedule.title} was removed from your calendar.`);
                renderCalendar();
            } catch (error) {
                console.error('Calendar schedule deletion failed.', error);
                announce('Schedule could not be removed. Please try again.');
            } finally {
                deleteScheduleButton.disabled = false;
            }

            return;
        }

        if (monthButton) {
            updateMonth(Number(monthButton.dataset.calendarMonthShift));

            return;
        }

        if (dayButton) {
            selectedDate = dayButton.dataset.calendarDay;
            scheduleDateInput.value = selectedDate < currentToday ? currentToday : selectedDate;
            renderCalendar();

            return;
        }

        if (filterButton) {
            activeFilter = filterButton.dataset.calendarFilter;
            renderCalendar();
        }
    });

    root.querySelector('[data-calendar-today]')?.addEventListener('click', () => {
        selectedDate = currentToday;
        monthDate = parseDate(currentToday);
        monthDate.setDate(1);
        scheduleDateInput.value = currentToday;
        renderCalendar();
    });

    root.querySelector('[data-calendar-focus-form]')?.addEventListener('click', () => {
        scheduleForm.scrollIntoView({behavior: 'smooth', block: 'center'});
        scheduleTitleInput.focus();
    });

    scheduleForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!(scheduleForm instanceof HTMLFormElement) || !(scheduleSubmitButton instanceof HTMLButtonElement) || scheduleSubmitButton.disabled) {
            return;
        }

        const formData = new FormData(scheduleForm);
        const title = String(formData.get('title') || '').trim();
        const date = String(formData.get('date') || '');
        const time = String(formData.get('time') || '');

        if (!title || !date || date < currentToday) {
            announce('Enter a schedule title and choose today or a future date.');

            return;
        }

        scheduleSubmitButton.disabled = true;

        try {
            const { data: result } = await request(scheduleForm.action, {
                method: 'POST',
                body: formData,
            });

            if (!isValidSchedule(result.event) || schedules.some((schedule) => schedule.id === result.event.id)) {
                console.error('The calendar schedule response was invalid.');
                announce('Schedule could not be saved. Refresh the page and try again.');
                return;
            }

            schedules.push(result.event);
            selectedDate = result.event.date;
            monthDate = parseDate(selectedDate);
            monthDate.setDate(1);
            activeFilter = 'all';
            updateFilterButtons();
            scheduleForm.reset();
            scheduleDateInput.value = selectedDate;
            announce(`Schedule saved for ${formatDate(selectedDate, {month: 'long', day: 'numeric'})}.`);
            renderCalendar();
        } catch (error) {
            console.error('Calendar schedule request failed.', error);
            const validationMessage = Object.values(error.data?.errors ?? {}).flat()[0];
            announce(validationMessage ?? error.data?.message ?? 'Schedule could not be saved. Please try again.');
        } finally {
            scheduleSubmitButton.disabled = false;
        }
    });

    notificationButton?.addEventListener('click', async () => {
        if (!('Notification' in window)) {
            announce('This browser does not support desktop notifications.');

            return;
        }

        try {
            const permission = await Notification.requestPermission();

            updateNotificationButton();

            if (permission === 'granted') {
                notifyUpcomingDeadlines();
                announce('Browser deadline alerts are enabled.');
            } else {
                announce('Browser alerts were not enabled. Deadline reminders remain visible in the calendar.');
            }
        } catch (error) {
            console.error('Unable to request browser notification permission.', error);
            announce('Browser alerts could not be enabled. Deadline reminders remain visible in the calendar.');
        }
    });
};

document.addEventListener('page:load', initializeCalendar);
initializeCalendar();
