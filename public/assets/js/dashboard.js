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
        reorderEndpoint: '/api/tiles-reorder.php',
        resizeEndpoint: '/api/tiles-resize.php',
        notesEndpoint: '/api/notes.php',
        bookmarksEndpoint: '/api/bookmarks.php',
        gridColumns: 4, // Number of columns in the grid
        gridCellSize: 200 // Minimum cell size in pixels (approximate)
    };

    // State
    let autoRefreshTimer = null;
    let suggestionsRefreshTimer = null;
    let lastUpdateTime = new Date();
    let isReorderMode = false;
    let draggedTile = null;
    let tilesLoadedCount = 0;
    let totalTilesToLoad = 0;
    let isResizing = false;
    let resizeHandle = null;
    let resizeTile = null;
    let resizeStartX = 0;
    let resizeStartY = 0;
    let resizeStartColSpan = 1;
    let resizeStartRowSpan = 1;

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
        setupTileResize();
        
        // Add global resize event listeners (only once)
        if (!window.tileResizeListenersAdded) {
            document.addEventListener('mousemove', handleResizeMove);
            document.addEventListener('mouseup', handleResizeEnd);
            window.tileResizeListenersAdded = true;
        }

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

            // Count tiles we actually load (exclude claude, notes, notes-list, bookmarks)
            tiles.forEach(tile => {
                const t = tile.dataset.tileType;
                if (t && t !== 'claude' && t !== 'notes' && t !== 'notes-list' && t !== 'bookmarks') {
                    totalTilesToLoad++;
                }
            });
        }

        tiles.forEach(tile => {
            const tileType = tile.dataset.tileType;
            if (tileType === 'claude') {
                // Initialize Claude tile structure if needed
                initializeClaudeTile(tile);
            } else if (tileType === 'notes' || tileType === 'notes-list' || tileType === 'bookmarks') {
                // Notes and bookmarks tiles don't need to be tracked for suggestions
                loadTileData(tile, false);
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
        
        // Re-setup resize handles if on desktop
        if (window.innerWidth > 768) {
            setupTileResize();
        }
    }

    /**
     * Load data for a single tile
     */
    async function loadTileData(tile, trackForSuggestions = false) {
        const tileType = tile.dataset.tileType;
        const tileId = parseInt(tile.dataset.tileId) || 0;
        const content = tile.querySelector('.tile-content');
        const refreshBtn = tile.querySelector('.tile-refresh');
        
        // Debug logging for notes tiles
        if (tileType === 'notes') {
            console.log('Loading notes tile:', { tileType, tileId, element: tile });
        }

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

            // Check if response is ok
            if (!response.ok) {
                let errorMessage = 'Failed to load data';
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.error || errorMessage;
                } catch (e) {
                    // If JSON parsing fails, use status text
                    errorMessage = response.status === 401 ? 'Unauthorized - please refresh the page' : errorMessage;
                }
                showTileError(content, errorMessage);
                return;
            }

            const data = await response.json();

            // Debug logging for notes tiles
            if (tileType === 'notes') {
                console.log('Notes tile response:', data);
            }

            if (data.error) {
                console.error('Tile API error:', { tileType, tileId, error: data.error });
                showTileError(content, data.error);
            } else {
                renderTileContent(tileType, content, data, tile);
            }
        } catch (error) {
            console.error('Error loading tile:', { tileType, tileId, error });
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
        container.innerHTML = "<div class=\"tile-error\"><svg class=\"w-8 h-8 mb-2\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z\"/></svg><p>" + escapeHtml(message) + "</p><button type=\"button\" class=\"tile-retry-btn\" data-retry=\"tile\">Try Again</button></div>";
        var retryBtn = container.querySelector(".tile-retry-btn");
        if (retryBtn) {
            retryBtn.addEventListener("click", function() {
                var tile = container.closest(".tile");
                if (tile) {
                    var refreshBtn = tile.querySelector(".tile-refresh");
                    if (refreshBtn) refreshBtn.click();
                }
            });
        }
    }

    /**
     * Render tile content based on type
     */
    function renderTileContent(type, container, data, tileElement = null) {
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
            case 'notes':
                renderNotesTile(container, data, tileElement);
                break;
            case 'notes-list':
                renderNotesListTile(container, data);
                break;
            case 'bookmarks':
                renderBookmarksTile(container, data, tileElement);
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
     * Render notes tile
     */
    function renderNotesTile(container, data, tileElement) {
        var tileId = tileElement.dataset.tileId;
        var notes = data.notes || "";
        var currentNoteId = data.current_note_id || null;
        var saveTitle = currentNoteId ? "Update existing note" : "Save note to list and clear";
        var saveLabel = currentNoteId ? "Update Note" : "Save to List";
        var noteIdAttr = currentNoteId !== null && currentNoteId !== undefined ? String(currentNoteId) : "";

        container.innerHTML = "<div class=\"notes-container\"><textarea id=\"notes-textarea-" + tileId + "\" class=\"notes-textarea\" placeholder=\"Jot down your notes here... They will be saved automatically.\" rows=\"8\">" + escapeHtml(notes) + "</textarea><div class=\"notes-footer\"><div class=\"notes-status\"><span class=\"notes-saved-indicator\" id=\"notes-saved-" + tileId + "\"></span></div><div class=\"notes-footer-actions\"><button id=\"notes-open-popup-btn-" + tileId + "\" class=\"notes-open-popup-btn\" title=\"Open in larger window\"><svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4\"/></svg> Expand</button><button id=\"notes-save-btn-" + tileId + "\" class=\"notes-save-btn\" title=\"" + saveTitle + "\" data-current-note-id=\"" + noteIdAttr + "\"><svg class=\"w-4 h-4 mr-1\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 13l4 4L19 7\"/></svg> " + saveLabel + "</button></div></div></div>";

        // Setup auto-save
        setupNotesAutoSave(tileId);
        
        // Setup save to list button
        setupNotesSaveButton(tileId);
        
        // Setup popup button
        setupNotesPopupButton(tileId);
    }

    /**
     * Render notes list tile
     */
    function renderNotesListTile(container, data) {
        var notes = data.notes || [];

        if (notes.length === 0) {
            container.innerHTML = "<div class=\"notes-list-empty\"><p class=\"text-gray-500 text-sm\">No saved notes yet.</p><p class=\"text-gray-400 text-xs mt-1\">Save notes from the Quick Notes tile to see them here.</p></div>";
            return;
        }

        var notesHtml = notes.map(function(note) {
            var date = new Date(note.created_at);
            var dateStr = date.toLocaleDateString("en-US", {
                month: "short",
                day: "numeric",
                year: date.getFullYear() !== new Date().getFullYear() ? "numeric" : undefined
            });
            var timeStr = date.toLocaleTimeString("en-US", {
                hour: "numeric",
                minute: "2-digit",
                hour12: true
            });
            return "<div class=\"notes-list-item\" data-note-id=\"" + note.id + "\"><div class=\"notes-list-preview\">" + escapeHtml(note.preview) + "</div><div class=\"notes-list-date\">" + escapeHtml(dateStr) + " at " + escapeHtml(timeStr) + "</div></div>";
        }).join("");

        container.innerHTML = "<div class=\"notes-list-container\"><div class=\"notes-list\">" + notesHtml + "</div></div>";

        setupNotesListClickHandlers();
    }

    /**
     * Get domain from URL for favicon
     */
    function getBookmarkDomain(url) {
        try {
            return new URL(url).hostname;
        } catch (_) {
            return "";
        }
    }

    /**
     * Render bookmarks tile (string concat to avoid nested template literal parse errors)
     */
    function renderBookmarksTile(container, data, tileElement) {
        var bookmarks = data.bookmarks || [], tileId = tileElement ? parseInt(tileElement.dataset.tileId, 10) : 0;
        if (tileId !== tileId) tileId = 0;

        var faviconUrl = function(url) {
            var domain = getBookmarkDomain(url);
            if (!domain) return "";
            return "https://www.google.com/s2/favicons?domain=" + encodeURIComponent(domain) + "&sz=32";
        };

        var bookmarksHtml;
        if (bookmarks.length === 0) {
            bookmarksHtml = "<div class=\"bookmarks-empty\"><p class=\"text-gray-500 text-sm\">No bookmarks yet.</p><p class=\"text-gray-400 text-xs mt-1\">Add a URL below to get started.</p></div>";
        } else {
            var items = bookmarks.filter(function(b) { return b && (b.url || b.id); }).map(function(b) {
                var url = b.url || "";
                var domain = getBookmarkDomain(url);
                var favicon = faviconUrl(url);
                var label = b.title || domain || url;
                var imgOrPlaceholder = favicon
                    ? "<img class=\"bookmark-favicon\" src=\"" + escapeHtml(favicon) + "\" alt=\"\" width=\"32\" height=\"32\">"
                    : "<span class=\"bookmark-favicon-placeholder\"></span>";
                return "<div class=\"bookmark-item\" data-bookmark-id=\"" + b.id + "\"><a class=\"bookmark-link\" href=\"" + escapeHtml(url) + "\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"" + escapeHtml(label) + "\">" + imgOrPlaceholder + "</a><button type=\"button\" class=\"bookmark-delete\" data-bookmark-id=\"" + b.id + "\" title=\"Remove bookmark\" aria-label=\"Remove bookmark\"><svg class=\"w-3.5 h-3.5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M6 18L18 6M6 6l12 12\"/></svg></button></div>";
            });
            bookmarksHtml = "<div class=\"bookmarks-grid\">" + items.join("") + "</div>";
        }

        container.innerHTML = "<div class=\"bookmarks-tile\"><form class=\"bookmarks-add-form\" id=\"bookmarks-add-form-" + tileId + "\"><input type=\"url\" class=\"bookmarks-url-input\" placeholder=\"https://...\" required><button type=\"submit\" class=\"bookmarks-add-btn\">Add</button></form><div class=\"bookmarks-list\">" + bookmarksHtml + "</div></div>";

        setupBookmarksAddForm(container, tileElement);
        setupBookmarksDeleteHandlers(container, tileElement);
    }

    /**
     * Setup add-bookmark form
     */
    function setupBookmarksAddForm(container, tileElement) {
        const form = container.querySelector(".bookmarks-add-form");
        if (!form) return;

        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            const input = form.querySelector(".bookmarks-url-input");
            const url = (input && input.value) ? input.value.trim() : "";
            if (!url) return;

            const addBtn = form.querySelector(".bookmarks-add-btn");
            if (addBtn) addBtn.disabled = true;

            try {
                const response = await fetch(CONFIG.bookmarksEndpoint, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": CONFIG.csrfToken,
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    body: JSON.stringify({ action: "add", url: url })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || "Failed to add");
                if (input) input.value = "";
                if (tileElement) loadTileData(tileElement, false);
            } catch (err) {
                console.error("Add bookmark error:", err);
                alert(err.message || "Failed to add bookmark.");
            } finally {
                if (addBtn) addBtn.disabled = false;
            }
        });
    }

    /**
     * Setup delete handlers for bookmarks
     */
    function setupBookmarksDeleteHandlers(container, tileElement) {
        container.querySelectorAll(".bookmark-delete").forEach(function(btn) {
            btn.addEventListener("click", async function(e) {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(this.dataset.bookmarkId, 10);
                if (!id) return;
                if (!confirm("Remove this bookmark?")) return;

                try {
                    const response = await fetch(CONFIG.bookmarksEndpoint, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": CONFIG.csrfToken,
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: JSON.stringify({ action: "delete", id: id })
                    });
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || "Failed to delete");
                    if (tileElement) loadTileData(tileElement, false);
                } catch (err) {
                    console.error("Delete bookmark error:", err);
                    alert("Failed to remove bookmark.");
                }
            });
        });
    }

    /**
     * Setup save to list button
     */
    function setupNotesSaveButton(tileId) {
        const saveBtn = document.getElementById(`notes-save-btn-${tileId}`);
        const textarea = document.getElementById(`notes-textarea-${tileId}`);
        
        if (!saveBtn || !textarea) {
            return;
        }

        // Update button text when current_note_id changes (e.g., after loading a note)
        const updateButtonText = () => {
            const currentNoteId = saveBtn.dataset.currentNoteId;
            const buttonText = saveBtn.querySelector('svg').nextSibling;
            if (buttonText) {
                buttonText.textContent = currentNoteId ? ' Update Note' : ' Save to List';
            }
            saveBtn.title = currentNoteId ? 'Update existing note' : 'Save note to list and clear';
        };

        saveBtn.addEventListener('click', async function() {
            const notes = textarea.value.trim();
            
            if (!notes) {
                alert('Note is empty. Please enter some text before saving.');
                return;
            }

            const currentNoteId = saveBtn.dataset.currentNoteId;
            const isUpdating = currentNoteId && currentNoteId !== '';

            // Disable button during save
            saveBtn.disabled = true;
            saveBtn.textContent = isUpdating ? 'Updating...' : 'Saving...';

            try {
                const response = await fetch(CONFIG.notesEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'save_to_list',
                        tile_id: parseInt(tileId),
                        notes: notes
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Update button state based on response
                    if (data.updated && data.note_id) {
                        // Note was updated, keep current_note_id
                        saveBtn.dataset.currentNoteId = data.note_id.toString();
                    } else if (data.note_id) {
                        // New note was created, set current_note_id
                        saveBtn.dataset.currentNoteId = data.note_id.toString();
                    }

                    // Clear the textarea
                    textarea.value = '';
                    
                    // Clear current_note_id when tile is cleared (user can start fresh)
                    // This happens after save, so next time they type, it will create a new note
                    // But we keep it for now in case they want to continue editing
                    // Actually, let's keep it - if they want to start fresh, they can manually clear
                    // or we can add a "New Note" button later
                    
                    // Trigger auto-save to clear the tile content
                    textarea.dispatchEvent(new Event('input'));
                    
                    // Refresh notes list tile if it exists
                    refreshNotesListTile();
                    
                    // Show success feedback
                    saveBtn.textContent = data.updated ? 'Updated!' : 'Saved!';
                    setTimeout(() => {
                        updateButtonText();
                        saveBtn.disabled = false;
                    }, 1500);
                } else {
                    throw new Error(data.error || 'Failed to save');
                }
            } catch (error) {
                console.error('Error saving note to list:', error);
                alert('Failed to save note. Please try again.');
                updateButtonText();
                saveBtn.disabled = false;
            }
        });
    }

    /**
     * Setup click handlers for notes list items
     */
    function setupNotesListClickHandlers() {
        const items = document.querySelectorAll('.notes-list-item');
        
        items.forEach(item => {
            item.addEventListener('click', async function() {
                const noteId = parseInt(this.dataset.noteId);
                if (!noteId) return;

                // Find the notes editing tile
                const notesTile = document.querySelector('.tile[data-tile-type="notes"]');
                if (!notesTile) {
                    alert('Quick Notes tile not found. Please add it to your dashboard.');
                    return;
                }

                const notesTileId = parseInt(notesTile.dataset.tileId);
                if (!notesTileId) return;

                // Load the note
                try {
                    const response = await fetch(CONFIG.notesEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CONFIG.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'load_note',
                            note_id: noteId,
                            tile_id: notesTileId
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Reload the notes tile to show the loaded note
                        // This will also update the button to show "Update Note" since current_note_id is set
                        loadTileData(notesTile, false);
                    } else {
                        throw new Error(data.error || 'Failed to load note');
                    }
                } catch (error) {
                    console.error('Error loading note:', error);
                    alert('Failed to load note. Please try again.');
                }
            });
        });
    }

    /**
     * Refresh the notes list tile
     */
    function refreshNotesListTile() {
        const notesListTile = document.querySelector('.tile[data-tile-type="notes-list"]');
        if (notesListTile) {
            loadTileData(notesListTile, false);
        }
    }

    /**
     * Setup popup button for notes tile
     */
    function setupNotesPopupButton(tileId) {
        const popupBtn = document.getElementById(`notes-open-popup-btn-${tileId}`);
        if (!popupBtn) return;

        popupBtn.addEventListener('click', function() {
            openNotesPopup(tileId);
        });
    }

    /**
     * Open notes popup overlay
     */
    function openNotesPopup(tileId) {
        // Get current content and state
        const textarea = document.getElementById(`notes-textarea-${tileId}`);
        const saveBtn = document.getElementById(`notes-save-btn-${tileId}`);
        const savedIndicator = document.getElementById(`notes-saved-${tileId}`);
        
        if (!textarea) return;

        const currentContent = textarea.value;
        const currentNoteId = saveBtn ? saveBtn.dataset.currentNoteId || null : null;
        const noteIdAttr = (currentNoteId !== null && currentNoteId !== undefined) ? String(currentNoteId) : "";
        const saveBtnLabel = (currentNoteId !== null && currentNoteId !== "") ? "Update Note" : "Save to List";

        // Create overlay HTML
        const overlay = document.createElement('div');
        overlay.className = 'notes-overlay';
        overlay.id = "notes-overlay-" + tileId;
        overlay.innerHTML = [
            "<div class=\"notes-modal\" id=\"notes-modal-" + tileId + "\">",
            "  <div class=\"notes-modal-header\">",
            "    <h3 class=\"notes-modal-title\">Edit Note</h3>",
            "    <div class=\"notes-modal-actions\">",
            "      <button class=\"notes-modal-btn\" id=\"notes-expand-btn-" + tileId + "\" title=\"Expand/Contract\">",
            "        <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4\"/></svg>",
            "      </button>",
            "      <button class=\"notes-modal-btn\" id=\"notes-close-btn-" + tileId + "\" title=\"Close\">",
            "        <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M6 18L18 6M6 6l12 12\"/></svg>",
            "      </button>",
            "    </div>",
            "  </div>",
            "  <div class=\"notes-modal-body\">",
            "    <textarea id=\"notes-modal-textarea-" + tileId + "\" class=\"notes-modal-textarea\" placeholder=\"Jot down your notes here... They will be saved automatically.\">" + escapeHtml(currentContent) + "</textarea>",
            "  </div>",
            "  <div class=\"notes-modal-footer\">",
            "    <div class=\"notes-modal-status\"><span class=\"notes-saved-indicator\" id=\"notes-modal-saved-" + tileId + "\"></span></div>",
            "    <button id=\"notes-modal-save-btn-" + tileId + "\" class=\"notes-modal-save-btn\" data-current-note-id=\"" + noteIdAttr + "\" data-tile-id=\"" + tileId + "\">",
            "      <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" class=\"notes-modal-svg-icon\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 13l4 4L19 7\"/></svg>",
            "      " + saveBtnLabel,
            "    </button>",
            "  </div>",
            "</div>"
        ].join("\n");

        // Add to body
        document.body.appendChild(overlay);

        // Show overlay with animation
        setTimeout(() => {
            overlay.classList.add('show');
        }, 10);

        // Setup handlers
        setupNotesPopupHandlers(tileId);
    }

    /**
     * Close notes popup overlay
     */
    function closeNotesPopup(tileId) {
        const overlay = document.getElementById(`notes-overlay-${tileId}`);
        if (!overlay) return;

        // Sync content back to tile textarea
        const modalTextarea = document.getElementById(`notes-modal-textarea-${tileId}`);
        const tileTextarea = document.getElementById(`notes-textarea-${tileId}`);
        
        if (modalTextarea && tileTextarea) {
            tileTextarea.value = modalTextarea.value;
            // Trigger input event to sync auto-save
            tileTextarea.dispatchEvent(new Event('input'));
        }

        // Sync current_note_id back to tile button
        const modalSaveBtn = document.getElementById(`notes-modal-save-btn-${tileId}`);
        const tileSaveBtn = document.getElementById(`notes-save-btn-${tileId}`);
        
        if (modalSaveBtn && tileSaveBtn) {
            tileSaveBtn.dataset.currentNoteId = modalSaveBtn.dataset.currentNoteId || '';
        }

        // Hide overlay
        overlay.classList.remove('show');
        
        // Remove after animation
        setTimeout(() => {
            overlay.remove();
        }, 200);
    }

    /**
     * Toggle notes popup expand/collapse
     */
    function toggleNotesPopupExpand(tileId) {
        const modal = document.getElementById(`notes-modal-${tileId}`);
        if (!modal) return;

        modal.classList.toggle('expanded');
    }

    /**
     * Setup popup handlers
     */
    function setupNotesPopupHandlers(tileId) {
        const overlay = document.getElementById(`notes-overlay-${tileId}`);
        if (!overlay) return;

        // Close button
        const closeBtn = document.getElementById(`notes-close-btn-${tileId}`);
        if (closeBtn) {
            closeBtn.addEventListener('click', () => closeNotesPopup(tileId));
        }

        // Expand button
        const expandBtn = document.getElementById(`notes-expand-btn-${tileId}`);
        if (expandBtn) {
            expandBtn.addEventListener('click', () => toggleNotesPopupExpand(tileId));
        }

        // Close on backdrop click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeNotesPopup(tileId);
            }
        });

        // Close on Escape key
        const escapeHandler = function(e) {
            if (e.key === 'Escape') {
                closeNotesPopup(tileId);
                document.removeEventListener('keydown', escapeHandler);
            }
        };
        document.addEventListener('keydown', escapeHandler);

        // Setup auto-save in popup
        setupNotesPopupAutoSave(tileId);

        // Setup save button in popup
        setupNotesPopupSaveButton(tileId);
    }

    /**
     * Setup auto-save for popup textarea
     */
    function setupNotesPopupAutoSave(tileId) {
        const modalTextarea = document.getElementById(`notes-modal-textarea-${tileId}`);
        const savedIndicator = document.getElementById(`notes-modal-saved-${tileId}`);
        const tileTextarea = document.getElementById(`notes-textarea-${tileId}`);
        
        if (!modalTextarea || !savedIndicator) return;

        let saveTimeout = null;
        let isSaving = false;
        let lastSavedValue = modalTextarea.value;

        // Auto-save on input (debounced)
        modalTextarea.addEventListener('input', function() {
            const currentValue = this.value;

            // Sync to tile textarea immediately
            if (tileTextarea) {
                tileTextarea.value = currentValue;
            }

            // Clear existing timeout
            if (saveTimeout) {
                clearTimeout(saveTimeout);
            }

            // Show "typing..." indicator
            if (!isSaving) {
                savedIndicator.textContent = '';
            }

            // Set new timeout for auto-save (1 second after user stops typing)
            saveTimeout = setTimeout(async () => {
                if (currentValue === lastSavedValue) {
                    return; // No changes
                }

                isSaving = true;
                savedIndicator.textContent = 'Saving...';
                savedIndicator.className = 'notes-saved-indicator saving';

                try {
                    const response = await fetch(CONFIG.notesEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CONFIG.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'save',
                            tile_id: parseInt(tileId),
                            notes: currentValue
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        lastSavedValue = currentValue;
                        savedIndicator.textContent = 'Saved';
                        savedIndicator.className = 'notes-saved-indicator saved';

                        // Clear indicator after 2 seconds
                        setTimeout(() => {
                            if (savedIndicator.textContent === 'Saved') {
                                savedIndicator.textContent = '';
                                savedIndicator.className = 'notes-saved-indicator';
                            }
                        }, 2000);
                    } else {
                        throw new Error(data.error || 'Failed to save');
                    }
                } catch (error) {
                    console.error('Error auto-saving notes:', error);
                    savedIndicator.textContent = 'Error saving';
                    savedIndicator.className = 'notes-saved-indicator error';

                    // Clear error indicator after 3 seconds
                    setTimeout(() => {
                        if (savedIndicator.textContent === 'Error saving') {
                            savedIndicator.textContent = '';
                            savedIndicator.className = 'notes-saved-indicator';
                        }
                    }, 3000);
                } finally {
                    isSaving = false;
                }
            }, 1000);
        });

        // Save on blur
        modalTextarea.addEventListener('blur', function() {
            if (saveTimeout) {
                clearTimeout(saveTimeout);
                saveTimeout = null;
            }
        });
    }

    /**
     * Setup save button in popup
     */
    function setupNotesPopupSaveButton(tileId) {
        const saveBtn = document.getElementById(`notes-modal-save-btn-${tileId}`);
        const modalTextarea = document.getElementById(`notes-modal-textarea-${tileId}`);
        
        if (!saveBtn || !modalTextarea) return;

        const updateButtonText = () => {
            const currentNoteId = saveBtn.dataset.currentNoteId;
            const buttonText = saveBtn.querySelector('svg').nextSibling;
            if (buttonText) {
                buttonText.textContent = currentNoteId ? ' Update Note' : ' Save to List';
            }
        };

        saveBtn.addEventListener('click', async function() {
            const notes = modalTextarea.value.trim();
            
            if (!notes) {
                alert('Note is empty. Please enter some text before saving.');
                return;
            }

            const currentNoteId = saveBtn.dataset.currentNoteId;
            const isUpdating = currentNoteId && currentNoteId !== '';

            // Disable button during save
            saveBtn.disabled = true;
            var svg = "<svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" class=\"w-4 h-4\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 13l4 4L19 7\"/></svg> ";
            saveBtn.innerHTML = isUpdating ? svg + "Updating..." : svg + "Saving...";

            try {
                const response = await fetch(CONFIG.notesEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'save_to_list',
                        tile_id: parseInt(tileId),
                        notes: notes
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Update button state based on response
                    if (data.updated && data.note_id) {
                        saveBtn.dataset.currentNoteId = data.note_id.toString();
                    } else if (data.note_id) {
                        saveBtn.dataset.currentNoteId = data.note_id.toString();
                    }

                    // Sync to tile textarea
                    const tileTextarea = document.getElementById(`notes-textarea-${tileId}`);
                    const tileSaveBtn = document.getElementById(`notes-save-btn-${tileId}`);
                    if (tileTextarea) {
                        tileTextarea.value = '';
                        tileTextarea.dispatchEvent(new Event('input'));
                    }
                    if (tileSaveBtn) {
                        tileSaveBtn.dataset.currentNoteId = saveBtn.dataset.currentNoteId;
                    }

                    // Clear modal textarea
                    modalTextarea.value = '';
                    
                    // Refresh notes list tile if it exists
                    refreshNotesListTile();
                    
                    // Close popup
                    setTimeout(() => {
                        closeNotesPopup(tileId);
                    }, 500);
                } else {
                    throw new Error(data.error || 'Failed to save');
                }
            } catch (error) {
                console.error('Error saving note to list:', error);
                alert('Failed to save note. Please try again.');
                updateButtonText();
                saveBtn.disabled = false;
            }
        });
    }

    /**
     * Setup auto-save for notes tile
     */
    function setupNotesAutoSave(tileId) {
        const textarea = document.getElementById(`notes-textarea-${tileId}`);
        const savedIndicator = document.getElementById(`notes-saved-${tileId}`);
        
        if (!textarea || !savedIndicator) {
            return;
        }

        let saveTimeout = null;
        let isSaving = false;
        let lastSavedValue = textarea.value;

        // Auto-save on input (debounced)
        textarea.addEventListener('input', function() {
            const currentValue = this.value;

            // Clear existing timeout
            if (saveTimeout) {
                clearTimeout(saveTimeout);
            }

            // Show "typing..." indicator
            if (!isSaving) {
                savedIndicator.textContent = '';
            }

            // Set new timeout for auto-save (1 second after user stops typing)
            saveTimeout = setTimeout(async () => {
                if (currentValue === lastSavedValue) {
                    return; // No changes
                }

                isSaving = true;
                savedIndicator.textContent = 'Saving...';
                savedIndicator.className = 'notes-saved-indicator saving';

                try {
                    const response = await fetch(CONFIG.notesEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CONFIG.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            tile_id: parseInt(tileId),
                            notes: currentValue
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        lastSavedValue = currentValue;
                        savedIndicator.textContent = 'Saved';
                        savedIndicator.className = 'notes-saved-indicator saved';
                        
                        // Clear indicator after 2 seconds
                        setTimeout(() => {
                            if (savedIndicator.textContent === 'Saved') {
                                savedIndicator.textContent = '';
                                savedIndicator.className = 'notes-saved-indicator';
                            }
                        }, 2000);
                    } else {
                        throw new Error(data.error || 'Failed to save');
                    }
                } catch (error) {
                    console.error('Error saving notes:', error);
                    savedIndicator.textContent = 'Error saving';
                    savedIndicator.className = 'notes-saved-indicator error';
                    
                    // Clear error indicator after 3 seconds
                    setTimeout(() => {
                        if (savedIndicator.textContent === 'Error saving') {
                            savedIndicator.textContent = '';
                            savedIndicator.className = 'notes-saved-indicator';
                        }
                    }, 3000);
                } finally {
                    isSaving = false;
                }
            }, 1000); // Wait 1 second after user stops typing
        });

        // Also save on blur (when user clicks away)
        textarea.addEventListener('blur', function() {
            if (saveTimeout) {
                clearTimeout(saveTimeout);
                saveTimeout = null;
            }

            const currentValue = this.value;
            if (currentValue !== lastSavedValue && !isSaving) {
                // Trigger immediate save
                textarea.dispatchEvent(new Event('input'));
            }
        });
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
        if (!tile) {
            console.warn('Claude tile not found');
            return;
        }

        const content = tile.querySelector('.tile-content');
        if (!content) {
            console.warn('Claude tile content not found');
            return;
        }

        // Ensure Claude tile is initialized
        let claudeInterface = content.querySelector('.claude-interface');
        if (!claudeInterface) {
            console.log('Initializing Claude tile structure...');
            initializeClaudeTile(tile);
            claudeInterface = content.querySelector('.claude-interface');
        }

        if (!claudeInterface) {
            console.error('Failed to initialize Claude interface');
            return;
        }

        // Find or create suggestions area
        let messagesContainer = claudeInterface.querySelector('.claude-messages');
        if (!messagesContainer) {
            messagesContainer = document.createElement('div');
            messagesContainer.className = 'claude-messages';
            const inputForm = claudeInterface.querySelector('.claude-input-form');
            if (inputForm) {
                claudeInterface.insertBefore(messagesContainer, inputForm);
            } else {
                claudeInterface.appendChild(messagesContainer);
            }
        }

        let suggestionsArea = messagesContainer.querySelector('.ai-suggestions');
        if (!suggestionsArea) {
            suggestionsArea = document.createElement('div');
            suggestionsArea.className = 'ai-suggestions';
            messagesContainer.appendChild(suggestionsArea);
        }

        // Show loading state
        suggestionsArea.innerHTML = `
            <div class="suggestions-loading">
                <div class="loading-spinner"></div>
                <p>Analyzing your dashboard...</p>
            </div>
        `;

        try {
            console.log('Fetching AI suggestions from:', CONFIG.suggestionsEndpoint);
            const response = await fetch(CONFIG.suggestionsEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            console.log('Suggestions response status:', response.status, response.statusText);

            // Check if response is OK
            if (!response.ok) {
                const errorText = await response.text();
                console.error('HTTP error response:', errorText);
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let data;
            try {
                const responseText = await response.text();
                console.log('Response text:', responseText.substring(0, 500));
                data = JSON.parse(responseText);
            } catch (jsonError) {
                console.error('Failed to parse JSON response:', jsonError);
                throw new Error('Invalid response from server. Please try again.');
            }

            console.log('Suggestions response data:', data);

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
            const errorMsg = error.message || 'Unable to load suggestions. Please try again.';
            renderSuggestionsError(suggestionsArea, errorMsg);
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
                debugStr = "<br><small class=\"suggestions-debug\">Debug: CRM=" + (d.crm_connected ? "1" : "0") + ", Weather=" + (d.weather_configured ? "1" : "0") + ", Email=" + (d.email_connected ? "1" : "0") + "</small>";
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
        const labels = {
            'email': 'Mail',
            'calendar': 'Calendar',
            'tasks': 'Tasks',
            'crm': 'CRM',
            'weather': 'Weather',
            'general': ''
        };
        const label = labels[source] || labels['general'];
        return label ? `<span class="suggestion-source-label">${escapeHtml(label)}</span>` : '';
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

    /**
     * Setup tile resize functionality
     */
    function setupTileResize() {
        // Only enable resize on desktop (not mobile)
        if (window.innerWidth <= 768) {
            return;
        }

        const tiles = document.querySelectorAll('.tile-resizable');
        tiles.forEach(tile => {
            // Add resize handles if they don't exist
            if (!tile.querySelector('.tile-resize-handle-se')) {
                const seHandle = document.createElement('div');
                seHandle.className = 'tile-resize-handle tile-resize-handle-se';
                seHandle.title = 'Drag to resize';
                tile.appendChild(seHandle);
                
                const eHandle = document.createElement('div');
                eHandle.className = 'tile-resize-handle tile-resize-handle-e';
                eHandle.title = 'Drag to resize';
                tile.appendChild(eHandle);
                
                const sHandle = document.createElement('div');
                sHandle.className = 'tile-resize-handle tile-resize-handle-s';
                sHandle.title = 'Drag to resize';
                tile.appendChild(sHandle);
            }
            
            const handles = tile.querySelectorAll('.tile-resize-handle');
            handles.forEach(handle => {
                // Remove existing listeners and add new ones
                handle.removeEventListener('mousedown', handleResizeStart);
                handle.addEventListener('mousedown', handleResizeStart);
            });
        });
    }

    /**
     * Handle resize start
     */
    function handleResizeStart(e) {
        if (isReorderMode || isResizing) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        isResizing = true;
        resizeHandle = e.target;
        resizeTile = e.target.closest('.tile-resizable');
        
        if (!resizeTile) return;
        
        resizeTile.classList.add('resizing');
        resizeStartX = e.clientX;
        resizeStartY = e.clientY;
        resizeStartColSpan = parseInt(resizeTile.dataset.columnSpan) || 1;
        resizeStartRowSpan = parseInt(resizeTile.dataset.rowSpan) || 1;
        
        document.body.style.cursor = resizeHandle.classList.contains('tile-resize-handle-se') ? 'nwse-resize' :
                                      resizeHandle.classList.contains('tile-resize-handle-e') ? 'ew-resize' : 'ns-resize';
        document.body.style.userSelect = 'none';
    }

    /**
     * Handle resize move
     */
    function handleResizeMove(e) {
        if (!isResizing || !resizeTile) return;
        
        e.preventDefault();
        
        const deltaX = e.clientX - resizeStartX;
        const deltaY = e.clientY - resizeStartY;
        
        // Calculate grid cell size (approximate)
        const container = document.getElementById('tilesContainer');
        const containerWidth = container.offsetWidth;
        const gap = 24; // 1.5rem gap
        const cellWidth = (containerWidth - (gap * (CONFIG.gridColumns - 1))) / CONFIG.gridColumns;
        const cellHeight = CONFIG.gridCellSize + gap;
        
        let newColSpan = resizeStartColSpan;
        let newRowSpan = resizeStartRowSpan;
        
        // Determine resize direction based on handle
        if (resizeHandle.classList.contains('tile-resize-handle-se')) {
            // Southeast corner - resize both dimensions
            const colDelta = Math.round(deltaX / cellWidth);
            const rowDelta = Math.round(deltaY / cellHeight);
            newColSpan = Math.max(1, Math.min(4, resizeStartColSpan + colDelta));
            newRowSpan = Math.max(1, Math.min(4, resizeStartRowSpan + rowDelta));
        } else if (resizeHandle.classList.contains('tile-resize-handle-e')) {
            // East edge - resize width only
            const colDelta = Math.round(deltaX / cellWidth);
            newColSpan = Math.max(1, Math.min(4, resizeStartColSpan + colDelta));
        } else if (resizeHandle.classList.contains('tile-resize-handle-s')) {
            // South edge - resize height only
            const rowDelta = Math.round(deltaY / cellHeight);
            newRowSpan = Math.max(1, Math.min(4, resizeStartRowSpan + rowDelta));
        }
        
        // Apply new size
        resizeTile.style.gridColumn = `span ${newColSpan}`;
        resizeTile.style.gridRow = `span ${newRowSpan}`;
        resizeTile.dataset.columnSpan = newColSpan;
        resizeTile.dataset.rowSpan = newRowSpan;
    }

    /**
     * Handle resize end
     */
    async function handleResizeEnd(e) {
        if (!isResizing || !resizeTile) return;
        
        e.preventDefault();
        
        const newColSpan = parseInt(resizeTile.dataset.columnSpan) || 1;
        const newRowSpan = parseInt(resizeTile.dataset.rowSpan) || 1;
        const tileId = parseInt(resizeTile.dataset.tileId);
        
        // Reset UI
        resizeTile.classList.remove('resizing');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        
        isResizing = false;
        
        // Save to server if tile has an ID
        if (tileId && tileId > 0) {
            try {
                const response = await fetch(CONFIG.resizeEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        tiles: [{
                            id: tileId,
                            column_span: newColSpan,
                            row_span: newRowSpan
                        }]
                    })
                });
                
                const data = await response.json();
                if (!data.success) {
                    console.error('Failed to save tile size:', data.error);
                    // Revert on error
                    resizeTile.style.gridColumn = `span ${resizeStartColSpan}`;
                    resizeTile.style.gridRow = `span ${resizeStartRowSpan}`;
                    resizeTile.dataset.columnSpan = resizeStartColSpan;
                    resizeTile.dataset.rowSpan = resizeStartRowSpan;
                }
            } catch (error) {
                console.error('Error saving tile size:', error);
                // Revert on error
                resizeTile.style.gridColumn = `span ${resizeStartColSpan}`;
                resizeTile.style.gridRow = `span ${resizeStartRowSpan}`;
                resizeTile.dataset.columnSpan = resizeStartColSpan;
                resizeTile.dataset.rowSpan = resizeStartRowSpan;
            }
        }
        
        // Cleanup
        resizeHandle = null;
        resizeTile = null;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
