(() => {
    const hideCompletedSetsToggle = document.getElementById('hideCompletedSets');
    const storageKey = 'wf-prime-ownership-v1';
    const defaultState = { sets: {}, parts: {} };
    const pageRoot = document.querySelector('[data-refreshing][data-refresh-status][data-refresh-status-url]');
    const refreshStatusPanel = document.getElementById('refreshStatusPanel');
    const refreshStatusMessage = document.getElementById('refreshStatusMessage');
    const refreshStatusError = document.getElementById('refreshStatusError');
    const refreshStatusProgress = document.getElementById('refreshStatusProgress');
    const refreshStatusState = document.getElementById('refreshStatusState');
    const refreshStatusPhase = document.getElementById('refreshStatusPhase');
    const refreshStatusUpdated = document.getElementById('refreshStatusUpdated');
    const floatingSearchInput = document.getElementById('floatingSearchInput');
    const floatingSearchClear = document.getElementById('floatingSearchClear');

    const shouldPollRefreshStatus = () => {
        if (!pageRoot) {
            return false;
        }

        if (pageRoot.dataset.staticMode === 'true' || !pageRoot.dataset.refreshStatusUrl) {
            return false;
        }

        const isRefreshing = pageRoot.dataset.refreshing === 'true';
        const refreshStatus = pageRoot.dataset.refreshStatus || '';

        return isRefreshing || refreshStatus === 'queued' || refreshStatus === 'running';
    };

    const statusClassName = (status) => {
        if (status === 'failed') {
            return 'refresh-status-failed';
        }

        if (status === 'completed') {
            return 'refresh-status-completed';
        }

        return 'refresh-status-running';
    };

    const formatUpdatedAt = (value) => {
        if (!value) {
            return null;
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).format(date);
    };

    const renderRefreshStatus = (status) => {
        if (!pageRoot || !refreshStatusPanel) {
            return;
        }

        const resolvedStatus = status.status || 'unknown';
        pageRoot.dataset.refreshing = status.refreshing ? 'true' : 'false';
        pageRoot.dataset.refreshStatus = resolvedStatus;

        refreshStatusPanel.classList.remove('refresh-status-running', 'refresh-status-completed', 'refresh-status-failed');
        refreshStatusPanel.classList.add(statusClassName(resolvedStatus));

        if (refreshStatusMessage) {
            refreshStatusMessage.textContent = status.message || 'No refresh activity recorded yet.';
        }

        if (refreshStatusError) {
            if (status.error) {
                refreshStatusError.textContent = status.error;
                refreshStatusError.classList.remove('d-none');
            } else {
                refreshStatusError.textContent = '';
                refreshStatusError.classList.add('d-none');
            }
        }

        if (refreshStatusProgress) {
            if (status.total) {
                refreshStatusProgress.textContent = `Progress: ${status.current || 0} / ${status.total}`;
                refreshStatusProgress.classList.remove('d-none');
            } else {
                refreshStatusProgress.textContent = '';
                refreshStatusProgress.classList.add('d-none');
            }
        }

        if (refreshStatusState) {
            refreshStatusState.textContent = `Status: ${resolvedStatus}`;
        }

        if (refreshStatusPhase) {
            if (status.phase) {
                refreshStatusPhase.textContent = `Phase: ${status.phase}`;
                refreshStatusPhase.classList.remove('d-none');
            } else {
                refreshStatusPhase.textContent = '';
                refreshStatusPhase.classList.add('d-none');
            }
        }

        if (refreshStatusUpdated) {
            const formatted = formatUpdatedAt(status.updatedAt);
            if (formatted) {
                refreshStatusUpdated.textContent = `Updated: ${formatted}`;
                refreshStatusUpdated.classList.remove('d-none');
            } else {
                refreshStatusUpdated.textContent = '';
                refreshStatusUpdated.classList.add('d-none');
            }
        }
    };

    const pollRefreshStatus = async () => {
        if (!pageRoot) {
            return;
        }

        try {
            const response = await window.fetch(pageRoot.dataset.refreshStatusUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) {
                return;
            }

            const status = await response.json();
            renderRefreshStatus(status);

            if (shouldPollRefreshStatus()) {
                window.setTimeout(pollRefreshStatus, 5000);
            }
        } catch (error) {
            if (shouldPollRefreshStatus()) {
                window.setTimeout(pollRefreshStatus, 7000);
            }
        }
    };

    const loadState = () => {
        try {
            const saved = window.localStorage.getItem(storageKey);
            if (!saved) {
                return { ...defaultState };
            }

            const parsed = JSON.parse(saved);

            return {
                sets: parsed.sets ?? {},
                parts: parsed.parts ?? {},
            };
        } catch (error) {
            return { ...defaultState };
        }
    };

    let state = loadState();

    const persistState = () => {
        window.localStorage.setItem(storageKey, JSON.stringify(state));
    };

    const toggleLabel = (owned) => (owned ? 'Owned' : 'Missing');
    const toggleIcon = (owned) => (owned ? '\u2713' : '\u2715');

    const syncToggleButton = (button, owned) => {
        if (!button) {
            return;
        }

        button.classList.toggle('btn-success', owned);
        button.classList.toggle('btn-outline-danger', !owned);
        button.classList.toggle('is-owned', owned);
        button.setAttribute('aria-pressed', owned ? 'true' : 'false');
        button.setAttribute('title', toggleLabel(owned));

        const icon = button.querySelector('[data-toggle-icon]');
        if (icon) {
            icon.textContent = toggleIcon(owned);
        }
    };

    const applyTableFilters = (table, term = '', hideCompleted = false) => {
        table.querySelectorAll('tbody tr[data-set-slug]').forEach((row) => {
            const haystack = row.innerText.toLowerCase();
            const matchesSearch = haystack.includes(term);
            const matchesCompletion = !hideCompleted || !row.classList.contains('owned-row');

            row.style.display = matchesSearch && matchesCompletion ? '' : 'none';
        });
    };

    const applyAllTableFilters = () => {
        const hideCompleted = Boolean(hideCompletedSetsToggle?.checked);

        document.querySelectorAll('[data-search-target]').forEach((input) => {
            const targetId = input.getAttribute('data-search-target');
            const table = document.getElementById(targetId);
            if (!table) {
                return;
            }

            applyTableFilters(table, input.value.trim().toLowerCase(), hideCompleted);
        });
    };

    const syncOwnershipState = () => {
        document.querySelectorAll('[data-part-slug]').forEach((partCard) => {
            syncPartCard(partCard);
        });

        document.querySelectorAll('[data-set-slug]').forEach((row) => {
            syncSetRow(row);
        });

        syncOwnedCounters();
        applyAllTableFilters();
    };

    const activeSearchInput = () => document.querySelector('.tab-pane.active [data-search-target]');

    const syncFloatingSearchInput = () => {
        const input = activeSearchInput();
        if (floatingSearchInput && input) {
            floatingSearchInput.value = input.value;
            floatingSearchInput.disabled = false;
        } else if (floatingSearchInput) {
            floatingSearchInput.value = '';
            floatingSearchInput.disabled = true;
        }
    };

    const syncSearchClearButton = (input) => {
        const targetId = input.getAttribute('data-search-target');
        const button = document.querySelector(`[data-search-clear="${targetId}"]`);
        button?.classList.toggle('is-visible', input.value.trim() !== '');
    };

    const syncFloatingSearchClearButton = () => {
        floatingSearchClear?.classList.toggle('is-visible', Boolean(floatingSearchInput?.value.trim()));
    };

    const applyActiveSearchTerm = (term) => {
        const input = activeSearchInput();
        if (!input) {
            return;
        }

        input.value = term;
        syncSearchClearButton(input);
        syncFloatingSearchClearButton();
        const table = document.getElementById(input.getAttribute('data-search-target'));
        if (table) {
            applyTableFilters(table, term.trim().toLowerCase(), Boolean(hideCompletedSetsToggle?.checked));
        }
    };

    let activeOrderTooltip = null;
    let activeOrderInfo = null;

    const removeFloatingOrderTooltip = () => {
        activeOrderTooltip?.remove();
        activeOrderTooltip = null;
        activeOrderInfo?.classList.remove('is-floating-active');
        activeOrderInfo = null;
    };

    const positionFloatingOrderTooltip = (trigger, tooltip) => {
        const margin = 10;
        const triggerRect = trigger.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = document.documentElement.clientHeight;

        let left = triggerRect.right - tooltipRect.width;
        left = Math.max(margin, Math.min(left, viewportWidth - tooltipRect.width - margin));

        let top = triggerRect.top - tooltipRect.height - margin;
        if (top < margin) {
            top = triggerRect.bottom + margin;
        }
        top = Math.max(margin, Math.min(top, viewportHeight - tooltipRect.height - margin));

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    };

    const showFloatingOrderTooltip = (trigger) => {
        const sourceTooltip = trigger.querySelector('.order-tooltip');
        if (!sourceTooltip) {
            return;
        }

        removeFloatingOrderTooltip();

        const floatingTooltip = sourceTooltip.cloneNode(true);
        floatingTooltip.classList.add('order-tooltip-floating');
        document.body.append(floatingTooltip);

        activeOrderTooltip = floatingTooltip;
        activeOrderInfo = trigger;
        trigger.classList.add('is-floating-active');

        positionFloatingOrderTooltip(trigger, floatingTooltip);
        window.requestAnimationFrame(() => {
            floatingTooltip.classList.add('is-visible');
        });
    };

    document.querySelectorAll('.order-info').forEach((trigger) => {
        trigger.addEventListener('mouseenter', () => showFloatingOrderTooltip(trigger));
        trigger.addEventListener('focus', () => showFloatingOrderTooltip(trigger));
        trigger.addEventListener('mouseleave', removeFloatingOrderTooltip);
        trigger.addEventListener('blur', removeFloatingOrderTooltip);
    });

    window.addEventListener('scroll', removeFloatingOrderTooltip, { passive: true });
    window.addEventListener('resize', removeFloatingOrderTooltip);

    const syncPartCard = (partCard) => {
        const slug = partCard.dataset.partSlug;
        const owned = Boolean(state.parts[slug]);

        partCard.classList.toggle('owned-part', owned);
        syncToggleButton(partCard.querySelector('[data-toggle-kind="parts"]'), owned);
    };

    const syncRowParts = (row, owned) => {
        row.querySelectorAll('[data-part-slug]').forEach((card) => {
            state.parts[card.dataset.partSlug] = owned;
            syncPartCard(card);
        });
    };

    const syncOwnedCounters = () => {
        const ownedCounts = { warframe: 0, weapon: 0, other: 0 };

        document.querySelectorAll('tr[data-set-slug].owned-row').forEach((row) => {
            const category = row.dataset.setCategory;
            if (category in ownedCounts) {
                ownedCounts[category] += 1;
            }
        });

        document.querySelectorAll('[data-owned-counter]').forEach((counter) => {
            const category = counter.dataset.ownedCounter;
            const total = Number.parseInt(counter.dataset.total || '0', 10);
            const owned = ownedCounts[category] || 0;
            counter.textContent = `${owned}/${total}`;
        });
    };

    const syncSetRow = (row) => {
        const setSlug = row.dataset.setSlug;
        const setCategory = row.dataset.setCategory;
        const partCards = Array.from(row.querySelectorAll('[data-part-slug]'));
        const allPartsOwned = partCards.length > 0 && partCards.every((card) => Boolean(state.parts[card.dataset.partSlug]));

        if (setCategory === 'warframe') {
            state.sets[setSlug] = allPartsOwned;
        }

        const setOwned = Boolean(state.sets[setSlug]);

        row.classList.toggle('owned-row', setOwned);
        row.querySelectorAll('td').forEach((cell) => {
            cell.classList.toggle('owned-cell', setOwned);
        });

        syncToggleButton(row.querySelector('[data-toggle-kind="sets"]'), setOwned);
    };

    document.querySelectorAll('[data-search-target]').forEach((input) => {
        const targetId = input.getAttribute('data-search-target');
        const table = document.getElementById(targetId);
        if (!table) {
            return;
        }

        input.addEventListener('input', () => {
            applyTableFilters(table, input.value.trim().toLowerCase(), Boolean(hideCompletedSetsToggle?.checked));
            syncSearchClearButton(input);
            if (input === activeSearchInput() && floatingSearchInput) {
                floatingSearchInput.value = input.value;
                syncFloatingSearchClearButton();
            }
        });
    });

    document.querySelectorAll('[data-search-clear]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.querySelector(`[data-search-target="${button.dataset.searchClear}"]`);
            if (!input) {
                return;
            }

            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
    });

    floatingSearchInput?.addEventListener('input', () => {
        syncFloatingSearchClearButton();
        applyActiveSearchTerm(floatingSearchInput.value);
    });

    floatingSearchClear?.addEventListener('click', () => {
        if (floatingSearchInput) {
            floatingSearchInput.value = '';
        }
        syncFloatingSearchClearButton();
        applyActiveSearchTerm('');
        floatingSearchInput?.focus();
    });

    document.querySelectorAll('[data-bs-toggle="pill"]').forEach((tab) => {
        tab.addEventListener('shown.bs.tab', syncFloatingSearchInput);
    });

    hideCompletedSetsToggle?.addEventListener('change', () => {
        applyAllTableFilters();
    });

    document.querySelectorAll('[data-toggle-kind]').forEach((button) => {
        const kind = button.dataset.toggleKind;
        const slug = button.dataset.slug;
        const row = button.closest('tr[data-set-slug]');
        const partCard = button.closest('[data-part-slug]');

        button.addEventListener('click', () => {
            const bucket = kind === 'sets' ? state.sets : state.parts;
            bucket[slug] = !bucket[slug];

            if (kind === 'sets' && row) {
                syncRowParts(row, bucket[slug]);
            }

            if (kind === 'parts' && partCard) {
                syncPartCard(partCard);
            }

            if (row) {
                syncSetRow(row);
            } else {
                syncToggleButton(button, Boolean(bucket[slug]));
            }

            syncOwnedCounters();
            persistState();
            applyAllTableFilters();
        });
    });

    syncOwnershipState();
    document.querySelectorAll('[data-search-target]').forEach(syncSearchClearButton);
    syncFloatingSearchInput();
    syncFloatingSearchClearButton();

    if (shouldPollRefreshStatus()) {
        window.setTimeout(pollRefreshStatus, 5000);
    }
})();
