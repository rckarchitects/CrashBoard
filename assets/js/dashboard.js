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
        claudeEndpoint: '/api/claude/query.php',
        suggestionsEndpoint: '/api/claude/suggestions.php',
        reorderEndpoint: '/api/tiles-reorder.php'
    };

    // State
    let autoRefreshTimer = null;
    let suggestionsRefreshTimer = null;
    let lastUpdateTime = new Date();
    let isReorderMode = false;
    let draggedTile = null;
    let tilesLoadedCount = 0;
    let totalTilesToLoad = 0;

    /**
     * Initialize the dashboard
     */
    function init() {
        // Load all tiles on page load (with flag to trigger suggestions after all tiles load)
        loadAllTiles(true);

        // Setup event listeners
        setupRefreshButtons();
        setupAutoRefresh();
        setupClaudeInterface();
        setupReorderMode();

        // Note: Suggestions are automatically refreshed when tiles reload
        // This timer is a fallback in case the user doesn't trigger a tile refresh
        suggestionsRefreshTimer = setInterval(loadAISuggestions, 600000);

        // Update last update time display
        updateLastUpdateDisplay();
        setInterval(updateLastUpdateDisplay, 30000); // Update every 30 seconds
    }

    /**
     * Load all tiles
     */
    function loadAllTiles(loadSuggestionsAfter = false) {
        const tiles = document.querySelectorAll('.tile[data-tile-type]');

        // Reset counters if we're tracking for suggestions
        if (loadSuggestionsAfter) {
            tilesLoadedCount = 0;
            totalTilesToLoad = 0;

            // Count non-claude tiles
            tiles.forEach(tile => {
                if (tile.dataset.tileType !== 'claude') {
                    totalTilesToLoad++;
                }
            });
        }

        tiles.forEach(tile => {
            const tileType = tile.dataset.tileType;
            if (tileType === 'claude') {
                // Initialize Claude tile structure if needed
                initializeClaudeTile(tile);
            } else {
                loadTileData(tile, loadSuggestionsAfter);
            }
        });

        // If no tiles to load, trigger suggestions immediately
        if (loadSuggestionsAfter && totalTilesToLoad === 0) {
            loadAISuggestions();
        }
    }

    /**
     * Initialize Claude AI tile with proper structure
     */
    function initializeClaudeTile(tile) {
        const content = tile.querySelector('.tile-content');
        if (!content) return;

        // Check if already has the claude-interface structure
        if (content.querySelector('.claude-interface')) return;

        // Create the full Claude interface structure
        content.innerHTML = `
            <div class="claude-interface">
                <div id="claudeMessages" class="claude-messages">
                    <!-- AI Suggestions Section -->
                    <div class="ai-suggestions">
                        <div class="suggestions-loading">
                            <div class="loading-spinner"></div>
                            <p>Analyzing your dashboard...</p>
                        </div>
                    </div>
                    <!-- Chat Section (hidden initially, shown when user asks a question) -->
                    <div class="claude-chat hidden"></div>
                </div>
                <form id="claudeForm" class="claude-input-form">
                    <input
                        type="text"
                        id="claudeInput"
                        placeholder="Ask a question about your data..."
                        class="claude-input"
                        autocomplete="off"
                    >
                    <button type="submit" class="claude-submit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </form>
            </div>
        `;

        // Re-setup the Claude interface event handlers
        setupClaudeInterface();
    }

    /**
     * Load data for a single tile
     */
    async function loadTileData(tile, trackForSuggestions = false) {
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

            // Track tile load completion for suggestions
            if (trackForSuggestions) {
                tilesLoadedCount++;
                console.log(`Tile loaded: ${tileType} (${tilesLoadedCount}/${totalTilesToLoad})`);

                // When all tiles are loaded, fetch suggestions
                if (tilesLoadedCount >= totalTilesToLoad) {
                    console.log('All tiles loaded, fetching AI suggestions...');
                    // Small delay to ensure cache is written
                    setTimeout(loadAISuggestions, 500);
                }
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
            case 'weather':
                renderWeatherTile(container, data);
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
     * Render weather tile
     */
    function renderWeatherTile(container, data) {
        if (!data.configured) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Configure weather settings to see forecast</p>
                    <a href="/settings.php" class="tile-connect-btn">Configure Weather</a>
                </div>
            `;
            return;
        }

        if (data.error) {
            container.innerHTML = `
                <div class="tile-error">
                    <svg class="w-8 h-8 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p>${escapeHtml(data.error)}</p>
                </div>
            `;
            return;
        }

        const current = data.current;
        const forecast = data.forecast || [];

        // Build forecast HTML
        const forecastHtml = forecast.map(day => `
            <div class="weather-forecast-day">
                <div class="weather-forecast-label">${escapeHtml(day.day)}</div>
                <div class="weather-forecast-icon">${getWeatherIconSvg(day.icon)}</div>
                <div class="weather-forecast-temps">
                    <span class="weather-high">${day.high}°</span>
                    <span class="weather-low">${day.low}°</span>
                </div>
                ${day.precipProbability > 20 ? `<div class="weather-precip">${day.precipProbability}%</div>` : ''}
            </div>
        `).join('');

        container.innerHTML = `
            <div class="weather-container">
                <div class="weather-current">
                    <div class="weather-main">
                        <div class="weather-icon-large">${getWeatherIconSvg(current.icon)}</div>
                        <div class="weather-temp">${current.temperature}${data.units}</div>
                    </div>
                    <div class="weather-details">
                        <div class="weather-location">${escapeHtml(data.location)}</div>
                        <div class="weather-description">${escapeHtml(current.description)}</div>
                        <div class="weather-meta">
                            <span title="Feels like">Feels ${current.feelsLike}°</span>
                            <span title="Humidity">💧 ${current.humidity}%</span>
                            <span title="Wind speed">💨 ${current.windSpeed} mph</span>
                        </div>
                    </div>
                </div>
                <div class="weather-forecast">
                    ${forecastHtml}
                </div>
            </div>
        `;
    }

    /**
     * Get SVG for weather icon
     */
    function getWeatherIconSvg(iconName) {
        const icons = {
            'sun': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
            'cloud-sun': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><path d="M22 10a3 3 0 0 0-3-3h-2.207a5.502 5.502 0 0 0-10.702.5"/></svg>',
            'cloud': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',
            'fog': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><line x1="3" y1="16" x2="21" y2="16"/><line x1="3" y1="20" x2="21" y2="20"/></svg>',
            'cloud-drizzle': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><line x1="8" y1="15" x2="8" y2="17"/><line x1="12" y1="15" x2="12" y2="17"/><line x1="16" y1="15" x2="16" y2="17"/></svg>',
            'cloud-rain': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><line x1="8" y1="15" x2="8" y2="21"/><line x1="12" y1="15" x2="12" y2="21"/><line x1="16" y1="15" x2="16" y2="21"/></svg>',
            'cloud-showers': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><line x1="7" y1="15" x2="7" y2="20"/><line x1="10" y1="17" x2="10" y2="22"/><line x1="14" y1="15" x2="14" y2="20"/><line x1="17" y1="17" x2="17" y2="22"/></svg>',
            'snowflake': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/><line x1="19.07" y1="4.93" x2="4.93" y2="19.07"/></svg>',
            'cloud-snow': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><circle cx="8" cy="16" r="1"/><circle cx="12" cy="18" r="1"/><circle cx="16" cy="16" r="1"/><circle cx="10" cy="21" r="1"/><circle cx="14" cy="21" r="1"/></svg>',
            'bolt': '<svg class="weather-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 12H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><polygon points="13 11 9 17 11 17 11 21 15 15 13 15 13 11"/></svg>'
        };
        return icons[iconName] || icons['cloud'];
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
                loadAllTiles(true); // Also refresh suggestions after tiles reload
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
                loadAllTiles(true); // Also refresh suggestions after tiles reload
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
     * Load AI suggestions based on dashboard data
     */
    async function loadAISuggestions() {
        const tile = document.querySelector('.tile[data-tile-type="claude"]');
        if (!tile) return;

        const content = tile.querySelector('.tile-content');
        if (!content) return;

        // Show loading state in suggestions area
        const suggestionsArea = content.querySelector('.ai-suggestions');
        if (suggestionsArea) {
            suggestionsArea.innerHTML = `
                <div class="suggestions-loading">
                    <div class="loading-spinner"></div>
                    <p>Analyzing your dashboard...</p>
                </div>
            `;
        }

        try {
            const response = await fetch(CONFIG.suggestionsEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();
            console.log('Suggestions response:', data);

            // Log debug info if present
            if (data.debug) {
                console.log('Suggestions debug info:', data.debug);
            }

            if (data.error) {
                console.error('Suggestions error:', data.error);
                renderSuggestionsError(suggestionsArea, data.error);
            } else {
                renderSuggestions(suggestionsArea, data);
            }
        } catch (error) {
            console.error('Error loading suggestions:', error);
            renderSuggestionsError(suggestionsArea, 'Unable to load suggestions. Check console for details.');
        }
    }

    /**
     * Render AI suggestions
     */
    function renderSuggestions(container, data) {
        if (!container) return;

        if (!data.hasData) {
            // Build debug info string if available
            let debugStr = '';
            if (data.debug) {
                const d = data.debug;
                debugStr = `<br><small style="color:#666;font-size:10px;">Debug: CRM=${d.crm_connected}, Weather=${d.weather_configured}, Email=${d.email_connected}</small>`;
            }
            container.innerHTML = `
                <div class="suggestions-empty">
                    <p>${escapeHtml(data.summary || 'Connect your services to get personalized suggestions.')}</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Services</a>
                    ${debugStr}
                </div>
            `;
            return;
        }

        let html = '';

        // Summary section
        if (data.summary) {
            html += `<div class="suggestions-summary">${escapeHtml(data.summary)}</div>`;
        }

        // Priorities section
        if (data.priorities && data.priorities.length > 0) {
            html += `<div class="suggestions-priorities">
                <h4>Top Priorities</h4>
                <ul>`;
            data.priorities.forEach(priority => {
                html += `<li>${escapeHtml(priority)}</li>`;
            });
            html += `</ul></div>`;
        }

        // Suggestions list
        if (data.suggestions && data.suggestions.length > 0) {
            html += `<div class="suggestions-list">
                <h4>Suggested Actions</h4>`;
            data.suggestions.forEach(suggestion => {
                const typeClass = `suggestion-${suggestion.type || 'general'}`;
                const sourceIcon = getSuggestionSourceIcon(suggestion.source);
                html += `
                    <div class="suggestion-item ${typeClass}">
                        <span class="suggestion-icon">${sourceIcon}</span>
                        <span class="suggestion-text">${escapeHtml(suggestion.text)}</span>
                    </div>
                `;
            });
            html += `</div>`;
        }

        // Refresh button
        html += `
            <div class="suggestions-footer">
                <button class="suggestions-refresh" onclick="window.refreshSuggestions()">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Refresh Suggestions
                </button>
            </div>
        `;

        container.innerHTML = html;
    }

    /**
     * Get icon for suggestion source
     */
    function getSuggestionSourceIcon(source) {
        const icons = {
            'email': '📧',
            'calendar': '📅',
            'tasks': '✅',
            'crm': '👥',
            'weather': '🌤️',
            'general': '💡'
        };
        return icons[source] || icons['general'];
    }

    /**
     * Render suggestions error
     */
    function renderSuggestionsError(container, message) {
        if (!container) return;
        container.innerHTML = `
            <div class="suggestions-error">
                <p>${escapeHtml(message)}</p>
                <button class="suggestions-refresh" onclick="window.refreshSuggestions()">Try Again</button>
            </div>
        `;
    }

    // Expose refresh function globally
    window.refreshSuggestions = function() {
        // Clear the suggestions cache by making a fresh request
        loadAISuggestions();
    };

    /**
     * Setup Claude AI interface
     */
    function setupClaudeInterface() {
        const form = document.getElementById('claudeForm');
        const input = document.getElementById('claudeInput');
        const messagesContainer = document.getElementById('claudeMessages');

        if (!form || !input || !messagesContainer) return;

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const query = input.value.trim();
            if (!query) return;

            // Clear input
            input.value = '';

            // Hide suggestions and show chat area
            const suggestions = messagesContainer.querySelector('.ai-suggestions');
            if (suggestions) suggestions.classList.add('hidden');

            let chatArea = messagesContainer.querySelector('.claude-chat');
            if (chatArea) {
                chatArea.classList.remove('hidden');
            } else {
                // Create chat area if it doesn't exist
                chatArea = document.createElement('div');
                chatArea.className = 'claude-chat';
                messagesContainer.appendChild(chatArea);
            }

            // Add user message
            addClaudeMessage(chatArea, query, 'user');

            // Show typing indicator
            const typing = addTypingIndicator(chatArea);

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
                    addClaudeMessage(chatArea, data.error, 'error');
                } else {
                    addClaudeMessage(chatArea, data.response, 'assistant');
                }
            } catch (error) {
                typing.remove();
                addClaudeMessage(chatArea, 'Sorry, something went wrong. Please try again.', 'error');
            }

            // Scroll to bottom
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
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

    /**
     * Setup reorder mode toggle and drag-and-drop
     */
    function setupReorderMode() {
        const reorderBtn = document.getElementById('reorderTiles');
        const saveBtn = document.getElementById('saveOrder');
        const cancelBtn = document.getElementById('cancelReorder');
        const container = document.getElementById('tilesContainer');

        if (!reorderBtn || !container) return;

        // Toggle reorder mode
        reorderBtn.addEventListener('click', () => {
            enterReorderMode();
        });

        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                saveNewOrder();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                exitReorderMode(false);
            });
        }
    }

    /**
     * Enter reorder mode
     */
    function enterReorderMode() {
        isReorderMode = true;
        const container = document.getElementById('tilesContainer');
        const reorderBtn = document.getElementById('reorderTiles');
        const reorderControls = document.getElementById('reorderControls');

        // Update UI
        container.classList.add('reorder-mode');
        if (reorderBtn) reorderBtn.classList.add('hidden');
        if (reorderControls) reorderControls.classList.remove('hidden');

        // Make tiles draggable
        const tiles = container.querySelectorAll('.tile');
        tiles.forEach(tile => {
            tile.setAttribute('draggable', 'true');
            tile.classList.add('reorder-tile');

            // Add drag handle indicator
            const header = tile.querySelector('.tile-header');
            if (header && !header.querySelector('.drag-handle')) {
                const handle = document.createElement('div');
                handle.className = 'drag-handle';
                handle.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                    </svg>
                `;
                header.insertBefore(handle, header.firstChild);
            }

            // Drag events
            tile.addEventListener('dragstart', handleDragStart);
            tile.addEventListener('dragend', handleDragEnd);
            tile.addEventListener('dragover', handleDragOver);
            tile.addEventListener('dragenter', handleDragEnter);
            tile.addEventListener('dragleave', handleDragLeave);
            tile.addEventListener('drop', handleDrop);
        });

        // Pause auto-refresh while reordering
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
        }
        if (suggestionsRefreshTimer) {
            clearInterval(suggestionsRefreshTimer);
        }
    }

    /**
     * Exit reorder mode
     */
    function exitReorderMode(saved = false) {
        isReorderMode = false;
        const container = document.getElementById('tilesContainer');
        const reorderBtn = document.getElementById('reorderTiles');
        const reorderControls = document.getElementById('reorderControls');

        // Update UI
        container.classList.remove('reorder-mode');
        if (reorderBtn) reorderBtn.classList.remove('hidden');
        if (reorderControls) reorderControls.classList.add('hidden');

        // Remove draggable attributes and event listeners
        const tiles = container.querySelectorAll('.tile');
        tiles.forEach(tile => {
            tile.removeAttribute('draggable');
            tile.classList.remove('reorder-tile', 'drag-over');

            // Remove drag handle
            const handle = tile.querySelector('.drag-handle');
            if (handle) handle.remove();

            // Remove event listeners by cloning
            const newTile = tile.cloneNode(true);
            tile.parentNode.replaceChild(newTile, tile);
        });

        // Re-setup refresh buttons after cloning
        setupRefreshButtons();

        // Resume auto-refresh
        setupAutoRefresh();

        // Resume suggestions refresh (every 10 minutes)
        suggestionsRefreshTimer = setInterval(loadAISuggestions, 600000);

        // Show feedback
        if (saved) {
            showToast('Tile order saved successfully!', 'success');
        }
    }

    /**
     * Handle drag start
     */
    function handleDragStart(e) {
        draggedTile = this;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.innerHTML);

        // Create a semi-transparent drag image
        setTimeout(() => {
            this.classList.add('drag-placeholder');
        }, 0);
    }

    /**
     * Handle drag end
     */
    function handleDragEnd(e) {
        this.classList.remove('dragging', 'drag-placeholder');

        // Remove drag-over class from all tiles
        document.querySelectorAll('.tile').forEach(tile => {
            tile.classList.remove('drag-over');
        });

        draggedTile = null;
    }

    /**
     * Handle drag over
     */
    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        e.dataTransfer.dropEffect = 'move';
        return false;
    }

    /**
     * Handle drag enter
     */
    function handleDragEnter(e) {
        if (this !== draggedTile) {
            this.classList.add('drag-over');
        }
    }

    /**
     * Handle drag leave
     */
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }

    /**
     * Handle drop
     */
    function handleDrop(e) {
        if (e.stopPropagation) {
            e.stopPropagation();
        }

        if (draggedTile !== this) {
            const container = document.getElementById('tilesContainer');
            const tiles = Array.from(container.querySelectorAll('.tile'));
            const draggedIndex = tiles.indexOf(draggedTile);
            const targetIndex = tiles.indexOf(this);

            if (draggedIndex < targetIndex) {
                this.parentNode.insertBefore(draggedTile, this.nextSibling);
            } else {
                this.parentNode.insertBefore(draggedTile, this);
            }
        }

        this.classList.remove('drag-over');
        return false;
    }

    /**
     * Save the new tile order
     */
    async function saveNewOrder() {
        const container = document.getElementById('tilesContainer');
        const tiles = container.querySelectorAll('.tile');
        const order = [];

        tiles.forEach((tile, index) => {
            const tileId = tile.dataset.tileId;
            if (tileId && tileId !== '0') {
                order.push({
                    id: parseInt(tileId),
                    position: index
                });
            }
        });

        try {
            const response = await fetch(CONFIG.reorderEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ order })
            });

            const data = await response.json();

            if (data.success) {
                exitReorderMode(true);
            } else {
                showToast(data.error || 'Failed to save order', 'error');
            }
        } catch (error) {
            console.error('Error saving order:', error);
            showToast('Failed to save order. Please try again.', 'error');
        }
    }

    /**
     * Show toast notification
     */
    function showToast(message, type = 'info') {
        // Remove existing toasts
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
