/**
 * CrashBoard Dashboard JavaScript
 *
 * Handles tile data loading, auto-refresh, and Claude AI interface.
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        refreshInterval: window.REFRESH_INTERVAL || 300000, // 5 minutes default
        csrfToken: window.CSRF_TOKEN || '',
        apiEndpoint: '/api/tiles.php',
        claudeEndpoint: '/api/claude/query.php'
    };

    // State
    let autoRefreshTimer = null;
    let lastUpdateTime = new Date();

    /**
     * Initialize the dashboard
     */
    function init() {
        // Load all tiles on page load
        loadAllTiles();

        // Setup event listeners
        setupRefreshButtons();
        setupAutoRefresh();
        setupClaudeInterface();

        // Update last update time display
        updateLastUpdateDisplay();
        setInterval(updateLastUpdateDisplay, 30000); // Update every 30 seconds
    }

    /**
     * Load all tiles
     */
    function loadAllTiles() {
        const tiles = document.querySelectorAll('.tile[data-tile-type]');
        tiles.forEach(tile => {
            const tileType = tile.dataset.tileType;
            if (tileType !== 'claude') {
                loadTileData(tile);
            }
        });
    }

    /**
     * Load data for a single tile
     */
    async function loadTileData(tile) {
        const tileType = tile.dataset.tileType;
        const tileId = tile.dataset.tileId;
        const content = tile.querySelector('.tile-content');
        const refreshBtn = tile.querySelector('.tile-refresh');

        // Show loading state
        content.innerHTML = `
            <div class="tile-loading">
                <div class="loading-spinner"></div>
                <p>Loading...</p>
            </div>
        `;

        if (refreshBtn) {
            refreshBtn.classList.add('refreshing');
        }

        try {
            const response = await fetch(CONFIG.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    type: tileType,
                    tile_id: tileId
                })
            });

            const data = await response.json();

            if (data.error) {
                showTileError(content, data.error);
            } else {
                renderTileContent(tileType, content, data);
            }
        } catch (error) {
            console.error('Error loading tile:', error);
            showTileError(content, 'Failed to load data. Please try again.');
        } finally {
            if (refreshBtn) {
                refreshBtn.classList.remove('refreshing');
            }
        }
    }

    /**
     * Show error state in tile
     */
    function showTileError(container, message) {
        container.innerHTML = `
            <div class="tile-error">
                <svg class="w-8 h-8 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p>${escapeHtml(message)}</p>
                <button class="tile-retry-btn" onclick="this.closest('.tile').querySelector('.tile-refresh').click()">
                    Try Again
                </button>
            </div>
        `;
    }

    /**
     * Render tile content based on type
     */
    function renderTileContent(type, container, data) {
        switch (type) {
            case 'email':
                renderEmailTile(container, data);
                break;
            case 'calendar':
                renderCalendarTile(container, data);
                break;
            case 'todo':
                renderTodoTile(container, data);
                break;
            case 'crm':
                renderCrmTile(container, data);
                break;
            default:
                container.innerHTML = '<p class="text-gray-500 text-sm">Unknown tile type</p>';
        }
    }

    /**
     * Render email tile
     */
    function renderEmailTile(container, data) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect Microsoft 365 to view emails</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (!data.emails || data.emails.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <p class="empty-state-text">No unread emails</p>
                </div>
            `;
            return;
        }

        const emailsHtml = data.emails.map(email => `
            <li class="email-item ${email.isRead ? '' : 'unread'}">
                <div class="flex justify-between items-start">
                    <span class="email-from">${escapeHtml(email.from)}</span>
                    <span class="email-time">${escapeHtml(email.receivedTime)}</span>
                </div>
                <div class="email-subject">${escapeHtml(email.subject)}</div>
                <div class="email-preview">${escapeHtml(email.preview)}</div>
            </li>
        `).join('');

        container.innerHTML = `
            <ul class="email-list">
                ${emailsHtml}
            </ul>
            ${data.unreadCount > data.emails.length ? `
                <p class="text-xs text-gray-500 mt-3 text-center">
                    +${data.unreadCount - data.emails.length} more unread
                </p>
            ` : ''}
        `;
    }

    /**
     * Render calendar tile
     */
    function renderCalendarTile(container, data) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect Microsoft 365 to view calendar</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (!data.events || data.events.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="empty-state-text">No events today</p>
                </div>
            `;
            return;
        }

        const eventsHtml = data.events.map(event => `
            <li class="calendar-item">
                <div class="calendar-indicator"></div>
                <div class="calendar-time">${escapeHtml(event.startTime)}</div>
                <div class="calendar-details">
                    <div class="calendar-title">${escapeHtml(event.subject)}</div>
                    ${event.location ? `<div class="calendar-location">${escapeHtml(event.location)}</div>` : ''}
                </div>
            </li>
        `).join('');

        container.innerHTML = `<ul class="calendar-list">${eventsHtml}</ul>`;
    }

    /**
     * Render todo/tasks tile
     */
    function renderTodoTile(container, data) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect Microsoft 365 to view tasks</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (!data.tasks || data.tasks.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    <p class="empty-state-text">No tasks</p>
                </div>
            `;
            return;
        }

        const tasksHtml = data.tasks.map(task => `
            <li class="task-item ${task.completed ? 'completed' : ''}">
                <div class="task-checkbox ${task.completed ? 'completed' : ''}" data-task-id="${task.id}"></div>
                <div class="task-content">
                    <div class="task-title">
                        ${task.importance === 'high' ? '<span class="task-priority high"></span>' : ''}
                        ${escapeHtml(task.title)}
                    </div>
                    ${task.dueDate ? `<div class="task-meta">Due: ${escapeHtml(task.dueDate)}</div>` : ''}
                </div>
            </li>
        `).join('');

        container.innerHTML = `<ul class="task-list">${tasksHtml}</ul>`;
    }

    /**
     * Render CRM tile
     */
    function renderCrmTile(container, data) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect OnePageCRM to view actions</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (!data.actions || data.actions.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <p class="empty-state-text">No pending actions</p>
                </div>
            `;
            return;
        }

        const actionsHtml = data.actions.map(action => `
            <li class="crm-item">
                <div class="crm-contact">${escapeHtml(action.contactName)}</div>
                <div class="crm-action">${escapeHtml(action.actionText)}</div>
                <div class="crm-due ${action.isOverdue ? 'overdue' : ''}">
                    ${action.isOverdue ? 'Overdue: ' : 'Due: '}${escapeHtml(action.dueDate)}
                </div>
            </li>
        `).join('');

        container.innerHTML = `<ul class="crm-list">${actionsHtml}</ul>`;
    }

    /**
     * Setup refresh button handlers
     */
    function setupRefreshButtons() {
        // Individual tile refresh
        document.querySelectorAll('.tile-refresh').forEach(btn => {
            btn.addEventListener('click', function() {
                const tile = this.closest('.tile');
                if (tile.dataset.tileType !== 'claude') {
                    loadTileData(tile);
                }
            });
        });

        // Refresh all button
        const refreshAllBtn = document.getElementById('refreshAll');
        if (refreshAllBtn) {
            refreshAllBtn.addEventListener('click', function() {
                loadAllTiles();
                lastUpdateTime = new Date();
                updateLastUpdateDisplay();
            });
        }
    }

    /**
     * Setup auto-refresh
     */
    function setupAutoRefresh() {
        if (CONFIG.refreshInterval > 0) {
            autoRefreshTimer = setInterval(() => {
                loadAllTiles();
                lastUpdateTime = new Date();
                updateLastUpdateDisplay();
            }, CONFIG.refreshInterval);

            // Update status display
            const statusEl = document.getElementById('autoRefreshStatus');
            if (statusEl) {
                statusEl.textContent = 'enabled';
                statusEl.className = 'font-medium text-green-600';
            }
        }
    }

    /**
     * Update last update time display
     */
    function updateLastUpdateDisplay() {
        const el = document.getElementById('lastUpdate');
        if (!el) return;

        const now = new Date();
        const diff = Math.floor((now - lastUpdateTime) / 1000);

        let text = 'Last updated: ';
        if (diff < 60) {
            text += 'just now';
        } else if (diff < 3600) {
            const mins = Math.floor(diff / 60);
            text += `${mins} minute${mins > 1 ? 's' : ''} ago`;
        } else {
            text += lastUpdateTime.toLocaleTimeString();
        }

        el.textContent = text;
    }

    /**
     * Setup Claude AI interface
     */
    function setupClaudeInterface() {
        const form = document.getElementById('claudeForm');
        const input = document.getElementById('claudeInput');
        const messages = document.getElementById('claudeMessages');

        if (!form || !input || !messages) return;

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const query = input.value.trim();
            if (!query) return;

            // Clear input
            input.value = '';

            // Remove welcome message if present
            const welcome = messages.querySelector('.claude-welcome');
            if (welcome) welcome.remove();

            // Add user message
            addClaudeMessage(messages, query, 'user');

            // Show typing indicator
            const typing = addTypingIndicator(messages);

            try {
                const response = await fetch(CONFIG.claudeEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ query })
                });

                const data = await response.json();

                // Remove typing indicator
                typing.remove();

                if (data.error) {
                    addClaudeMessage(messages, data.error, 'error');
                } else {
                    addClaudeMessage(messages, data.response, 'assistant');
                }
            } catch (error) {
                typing.remove();
                addClaudeMessage(messages, 'Sorry, something went wrong. Please try again.', 'error');
            }

            // Scroll to bottom
            messages.scrollTop = messages.scrollHeight;
        });
    }

    /**
     * Add message to Claude chat
     */
    function addClaudeMessage(container, text, type) {
        const div = document.createElement('div');
        div.className = `claude-message ${type}`;
        div.textContent = text;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }

    /**
     * Add typing indicator
     */
    function addTypingIndicator(container) {
        const div = document.createElement('div');
        div.className = 'claude-typing';
        div.innerHTML = `
            <span class="claude-typing-dot"></span>
            <span class="claude-typing-dot"></span>
            <span class="claude-typing-dot"></span>
        `;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        return div;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
