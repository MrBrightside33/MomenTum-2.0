import { request } from './http';

let refreshPromise = null;
let refreshRequested = false;

const refreshRegionsOnce = async () => {
    const currentRegions = Array.from(document.querySelectorAll('[data-refresh][id]'));

    if (currentRegions.length === 0) {
        return;
    }

    const searchInput = document.querySelector('[data-project-search]');
    const searchValue = searchInput instanceof HTMLInputElement ? searchInput.value : null;
    const activeProjectFilter = document.querySelector('[data-project-filter][aria-pressed="true"]')?.dataset.projectFilter;
    const activeStatusFilter = document.querySelector('[data-dashboard-status-filter][aria-pressed="true"]')?.dataset.dashboardStatusFilter;
    const activePriorityFilter = document.querySelector('[data-dashboard-priority-filter][aria-pressed="true"]')?.dataset.dashboardPriorityFilter;
    const sortValue = document.querySelector('[data-dashboard-task-sort]')?.value;
    const { data: documentText } = await request(window.location.href, {
        headers: { Accept: 'text/html' },
        responseType: 'text',
    });

    const refreshedDocument = new DOMParser().parseFromString(documentText, 'text/html');

    currentRegions.forEach((currentRegion) => {
        const refreshedRegion = refreshedDocument.getElementById(currentRegion.id);

        if (!refreshedRegion) {
            throw new Error(`The refreshed page is missing #${currentRegion.id}.`);
        }

        currentRegion.replaceWith(refreshedRegion);
    });

    if (searchValue !== null) {
        const refreshedSearch = document.querySelector('[data-project-search]');

        if (refreshedSearch instanceof HTMLInputElement) {
            refreshedSearch.value = searchValue;
        }
    }

    document.dispatchEvent(new CustomEvent('regions:refreshed'));

    const statefulFilters = [
        ['[data-project-filter]', 'projectFilter', activeProjectFilter],
        ['[data-dashboard-status-filter]', 'dashboardStatusFilter', activeStatusFilter],
        ['[data-dashboard-priority-filter]', 'dashboardPriorityFilter', activePriorityFilter],
    ];

    statefulFilters.forEach(([selector, dataKey, value]) => {
        if (value === undefined) {
            return;
        }

        const filter = Array.from(document.querySelectorAll(selector))
            .find((button) => button.dataset[dataKey] === value);
        filter?.click();
    });

    const sortControl = document.querySelector('[data-dashboard-task-sort]');

    if (sortControl instanceof HTMLSelectElement && typeof sortValue === 'string') {
        sortControl.value = sortValue;
        sortControl.dispatchEvent(new Event('change', { bubbles: true }));
    }
};

export const refreshRegions = () => {
    refreshRequested = true;

    if (refreshPromise) {
        return refreshPromise;
    }

    refreshPromise = (async () => {
        try {
            while (refreshRequested) {
                refreshRequested = false;
                await refreshRegionsOnce();
            }
        } finally {
            refreshPromise = null;
        }
    })();

    return refreshPromise;
};
