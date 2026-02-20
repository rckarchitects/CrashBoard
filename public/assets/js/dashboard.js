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
        moveScreenEndpoint: '/api/tiles-move-screen.php',
        resizeEndpoint: '/api/tiles-resize.php',
        notesEndpoint: '/api/notes.php',
        bookmarksEndpoint: '/api/bookmarks.php',
        linkBoardEndpoint: '/api/link-board.php',
        tasksEndpoint: '/api/tasks.php',
        gridColumns: 4, // Number of columns in the grid
        gridCellSize: 200 // Minimum cell size in pixels (approximate)
    };

    const screenLabels = (typeof window !== 'undefined' && window.DASHBOARD_SCREEN_LABELS)
        ? window.DASHBOARD_SCREEN_LABELS
        : { main: 'Main', screen2: 'Screen 2' };

    /**
     * Get the tiles container for the currently visible dashboard tab (Main or Screen 2).
     */
    function getActiveTilesContainer() {
        const panel = document.querySelector('.dashboard-panel:not(.hidden)');
        if (panel) {
            const grid = panel.querySelector('.tiles-grid');
            if (grid) return grid;
        }
        return document.getElementById('tilesContainer');
    }

    // State
    let autoRefreshTimer = null; // Deprecated - kept for backward compatibility
    let tileRefreshTimers = new Map(); // Map of tile element -> timer ID
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
        setupDashboardTabs();
        setupHeaderRollover();
        setupHeaderMenuButton();
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

        // Header date/time, session expiry, and last-updated display
        updateHeaderDateTime();
        setInterval(updateHeaderDateTime, 1000); // Update every second so time is dynamic
        updateSessionExpiryDisplay();
        setInterval(updateSessionExpiryDisplay, 1000); // Update every second (real-time countdown)
        updateLastUpdateDisplay();
        setInterval(updateLastUpdateDisplay, 30000); // Update every 30 seconds
    }

    /**
     * Update the header current date/time (all instances: desktop + mobile menu)
     */
    function updateHeaderDateTime() {
        const els = document.querySelectorAll('.header-date-time');
        if (!els.length) return;
        const now = new Date();
        const dateStr = now.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
        const timeStr = now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
        const text = dateStr + ', ' + timeStr;
        els.forEach(el => { el.textContent = text; });
    }

    /**
     * Format seconds as "Xd Xh" or "Xh Xm" or "Xm" or "expired"
     */
    function formatSessionTimeLeft(seconds) {
        if (seconds <= 0) return 'Session expired';
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        if (d > 0) return d + 'd ' + h + 'h left';
        if (h > 0) return h + 'h ' + m + 'm left';
        if (m > 0) return m + 'm left';
        return '< 1m left';
    }

    /**
     * Update the header session expiry indicator (all instances: desktop + mobile menu)
     */
    function updateSessionExpiryDisplay() {
        const els = document.querySelectorAll('.session-expiry-indicator');
        if (!els.length) return;
        const isPrivate = window.SESSION_PRIVATE_COMPUTER === true;
        const expiresAt = window.SESSION_EXPIRES_AT;
        if (isPrivate) {
            const title = 'You signed in on a private computer; session does not expire.';
            els.forEach(el => { el.textContent = 'Session: no expiry'; el.title = title; });
            return;
        }
        if (expiresAt == null) {
            els.forEach(el => { el.textContent = ''; el.title = ''; });
            return;
        }
        const now = Math.floor(Date.now() / 1000);
        const left = expiresAt - now;
        const text = 'Session: ' + formatSessionTimeLeft(left);
        const title = 'Session time remaining. When it expires you will need to sign in again.';
        els.forEach(el => { el.textContent = text; el.title = title; });
        if (left <= 0) {
            window.location.href = '/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
        }
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
                if (t && t !== 'claude' && t !== 'notes' && t !== 'notes-list' && t !== 'bookmarks' && t !== 'link-board') {
                    totalTilesToLoad++;
                }
            });
        }

        tiles.forEach(tile => {
            const tileType = tile.dataset.tileType;
            if (tileType === 'claude') {
                // Initialize Claude tile structure if needed
                initializeClaudeTile(tile);
            } else if (tileType === 'notes' || tileType === 'notes-list' || tileType === 'bookmarks' || tileType === 'link-board') {
                // Notes, bookmarks, and link-board tiles don't need to be tracked for suggestions
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
        const content = tile.querySelector('.tile-content-inner') || tile.querySelector('.tile-content');
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
        const content = tile.querySelector('.tile-content-inner') || tile.querySelector('.tile-content');
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
                if (response.status === 401) {
                    // Session ended; redirect to login instead of showing Unauthorized on every tile
                    window.location.href = '/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                    return;
                }
                let errorMessage = 'Failed to load data';
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.error || errorMessage;
                } catch (e) {
                    errorMessage = response.statusText || errorMessage;
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
                renderTodoTile(container, data, tileElement);
                break;
            case 'todo-personal':
                renderTodoTile(container, data, tileElement);
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
            case 'link-board':
                renderLinkBoardTile(container, data, tileElement);
                break;
            case 'calendar-next':
                renderCalendarNextTile(container, data);
                break;
            case 'next-event':
                renderNextEventTile(container, data);
                break;
            case 'flagged-email':
                renderFlaggedEmailTile(container, data);
                break;
            case 'flagged-email-count':
                renderFlaggedEmailCountTile(container, data);
                break;
            case 'overdue-tasks-count':
                renderOverdueTasksCountTile(container, data);
                break;
            case 'calendar-heatmap':
                renderCalendarHeatmapTile(container, data);
                break;
            case 'availability':
                renderAvailabilityTile(container, data);
                break;
            case 'train-departures':
                renderTrainDeparturesTile(container, data);
                break;
            case 'planner-overview':
                renderPlannerOverviewTile(container, data, tileElement);
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

        const unreadSvg = "<svg class=\"email-status-icon\" viewBox=\"0 0 24 24\" fill=\"currentColor\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"12\" r=\"4\"/></svg>";
        const flagSvg = "<svg class=\"email-status-icon\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z\"/><line x1=\"4\" y1=\"22\" x2=\"4\" y2=\"15\"/></svg>";
        const emailsHtml = data.emails.map(function(email) {
            var icons = "";
            if (!email.isRead) icons += "<span class=\"email-icon email-icon-unread\" title=\"Unread\">" + unreadSvg + "</span>";
            if (email.isFlagged) icons += "<span class=\"email-icon email-icon-flagged\" title=\"Flagged\">" + flagSvg + "</span>";
            var statusHtml = icons ? "<span class=\"email-status-icons\">" + icons + "</span>" : "";
            var previewFull = (email.previewFull != null && email.previewFull !== "") ? email.previewFull : (email.preview || "");
            var receivedDt = email.receivedDateTime || "";
            return "<li class=\"email-item email-item-clickable " + (email.isRead ? "" : "unread") + "\" data-email-subject=\"" + escapeHtml(email.subject) + "\" data-email-from=\"" + escapeHtml(email.from) + "\" data-email-preview-full=\"" + escapeHtml(previewFull) + "\" data-email-received-time=\"" + escapeHtml(email.receivedTime || "") + "\" data-email-received-datetime=\"" + escapeHtml(receivedDt) + "\"><div class=\"email-item-first-row\">" + statusHtml + "<div class=\"email-from-time\"><span class=\"email-from\">" + escapeHtml(email.from) + "</span><span class=\"email-time\">" + escapeHtml(email.receivedTime) + "</span></div></div><div class=\"email-subject\">" + escapeHtml(email.subject) + "</div><div class=\"email-preview\">" + escapeHtml(email.preview) + "</div></li>";
        }).join("");

        var moreHtml = data.unreadCount > data.emails.length ? "<p class=\"text-xs text-gray-500 mt-3 text-center\">+" + (data.unreadCount - data.emails.length) + " more unread</p>" : "";
        container.innerHTML = "<ul class=\"email-list\">" + emailsHtml + "</ul>" + moreHtml + "<div class=\"tile-content-bottom-pad\" aria-hidden=\"true\"></div>";

        container.querySelectorAll(".email-item-clickable").forEach(function(item) {
            item.addEventListener("click", function() {
                var email = {
                    subject: this.dataset.emailSubject || "(No Subject)",
                    from: this.dataset.emailFrom || "",
                    previewFull: this.dataset.emailPreviewFull || "",
                    receivedTime: this.dataset.emailReceivedTime || "",
                    receivedDateTime: this.dataset.emailReceivedDatetime || ""
                };
                openEmailDetailOverlay(email);
            });
        });
    }

    function formatEmailDateTime(isoString) {
        if (!isoString) return "";
        try {
            var d = new Date(isoString);
            if (isNaN(d.getTime())) return "";
            return d.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
        } catch (e) {
            return "";
        }
    }

    function openEmailDetailOverlay(email) {
        var overlay = document.getElementById("email-detail-overlay");
        if (!overlay) {
            overlay = document.createElement("div");
            overlay.className = "email-detail-overlay";
            overlay.id = "email-detail-overlay";
            overlay.innerHTML = "<div class=\"email-detail-modal\"><div class=\"email-detail-modal-header\"><h3 class=\"email-detail-modal-title\" id=\"email-detail-subject\"></h3><button type=\"button\" class=\"email-detail-modal-close\" id=\"email-detail-close-btn\" title=\"Close\"><svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M6 18L18 6M6 6l12 12\"/></svg></button></div><div class=\"email-detail-modal-body\"><div class=\"email-detail-meta\" id=\"email-detail-meta\"></div><div class=\"email-detail-preview\" id=\"email-detail-preview\"></div></div></div>";
            document.body.appendChild(overlay);
            document.getElementById("email-detail-close-btn").addEventListener("click", closeEmailDetailOverlay);
            overlay.addEventListener("click", function(e) {
                if (e.target === overlay) closeEmailDetailOverlay();
            });
            document.addEventListener("keydown", function emailDetailEscape(e) {
                if (e.key === "Escape" && document.getElementById("email-detail-overlay") && document.getElementById("email-detail-overlay").classList.contains("show")) closeEmailDetailOverlay();
            });
        }
        document.getElementById("email-detail-subject").textContent = email.subject;
        var dateTimeDisplay = formatEmailDateTime(email.receivedDateTime) || email.receivedTime || "";
        document.getElementById("email-detail-meta").innerHTML = "<div class=\"email-detail-meta-row\"><strong>From:</strong> " + escapeHtml(email.from) + "</div>" + (dateTimeDisplay ? "<div class=\"email-detail-meta-row\"><strong>Date:</strong> " + escapeHtml(dateTimeDisplay) + "</div>" : "");
        document.getElementById("email-detail-preview").textContent = email.previewFull || "No preview.";
        requestAnimationFrame(function() { overlay.classList.add("show"); });
    }

    function closeEmailDetailOverlay() {
        var overlay = document.getElementById("email-detail-overlay");
        if (overlay) overlay.classList.remove("show");
    }

    /**
     * Render flagged email reminder tile (one random flagged email from history)
     */
    function renderFlaggedEmailTile(container, data) {
        if (!data.connected) {
            container.innerHTML = "<div class=\"tile-placeholder\"><p>Connect Microsoft 365 to surface flagged emails</p><a href=\"/settings.php\" class=\"tile-connect-btn\">Connect Account</a></div>";
            return;
        }
        if (data.error) {
            container.innerHTML = "<div class=\"tile-placeholder\"><p class=\"text-red-600\">" + escapeHtml(data.error) + "</p><p class=\"text-sm mt-2\">Use the refresh button to retry.</p></div>";
            return;
        }
        if (!data.email || data.totalFlagged === 0) {
            container.innerHTML = "<div class=\"empty-state\"><svg class=\"empty-state-icon\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z\"/></svg><p class=\"empty-state-text\">No flagged emails in inbox</p><p class=\"text-xs opacity-75 mt-1\">Flag emails in Outlook to see them here as reminders.</p></div>";
            return;
        }
        var email = data.email;
        var openLabel = email.webLink ? "Open in Outlook" : "View in Outlook";
        var openLink = email.webLink || "https://outlook.office.com/mail/inbox";
        container.innerHTML = "<div class=\"flagged-email-tile\"><div class=\"flagged-email-card\"><div class=\"flagged-email-meta\"><span class=\"flagged-email-from\">" + escapeHtml(email.from) + "</span><span class=\"flagged-email-time\">" + escapeHtml(email.receivedTime) + "</span></div><h4 class=\"flagged-email-subject\">" + escapeHtml(email.subject) + "</h4>" + (email.preview ? "<p class=\"flagged-email-preview\">" + escapeHtml(email.preview) + "</p>" : "") + "<div class=\"flagged-email-actions mt-3 flex flex-wrap gap-2\"><a href=\"" + escapeHtml(openLink) + "\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2\" style=\"background-color: var(--cb-primary);\"><svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M10 6a2 2 0 012 2v2m0 4v6a2 2 0 01-2 2h-2a2 2 0 01-2-2V10a2 2 0 012-2h2\"/></svg> " + openLabel + "</a></div></div><p class=\"flagged-email-count text-xs opacity-75 mt-2\">" + data.totalFlagged + " flagged email" + (data.totalFlagged !== 1 ? "s" : "") + " in inbox · Refresh to see another</p></div>";
    }

    /**
     * Render flagged email count tile: displays total number of flagged emails as a large centered integer
     */
    function renderFlaggedEmailCountTile(container, data) {
        if (!data.connected) {
            container.innerHTML = "<div class=\"tile-placeholder\"><p>Connect Microsoft 365 to view flagged email count</p><a href=\"/settings.php\" class=\"tile-connect-btn\">Connect Account</a></div>";
            return;
        }
        if (data.error) {
            container.innerHTML = "<div class=\"tile-error\"><p>" + escapeHtml(data.error) + "</p><button class=\"tile-retry-btn\" onclick=\"this.closest('.tile').querySelector('.tile-refresh').click()\">Try again</button></div>";
            return;
        }
        var count = data.count !== undefined ? data.count : 0;
        container.innerHTML = "<div class=\"flagged-email-count-tile\"><div class=\"flagged-email-count-number\">" + escapeHtml(count.toString()) + "</div><div class=\"flagged-email-count-label\">Flagged email" + (count !== 1 ? "s" : "") + " in inbox</div></div>";
    }

    /**
     * Render overdue tasks count tile: total incomplete tasks with due date in the past (same style as flagged email count).
     */
    function renderOverdueTasksCountTile(container, data) {
        if (!data.connected) {
            container.innerHTML = "<div class=\"tile-placeholder\"><p>Connect Microsoft 365 to view overdue tasks count</p><a href=\"/settings.php\" class=\"tile-connect-btn\">Connect Account</a></div>";
            return;
        }
        if (data.error) {
            container.innerHTML = "<div class=\"tile-error\"><p>" + escapeHtml(data.error) + "</p><button class=\"tile-retry-btn\" onclick=\"this.closest('.tile').querySelector('.tile-refresh').click()\">Try again</button></div>";
            return;
        }
        var count = data.count !== undefined ? data.count : 0;
        container.innerHTML = "<div class=\"flagged-email-count-tile\"><div class=\"flagged-email-count-number\">" + escapeHtml(count.toString()) + "</div><div class=\"flagged-email-count-label\">Overdue task" + (count !== 1 ? "s" : "") + "</div></div>";
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

        if (!data.events || !Array.isArray(data.events) || data.events.length === 0) {
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

        const now = new Date();
        let currentEventIndex = -1;
        let closestEventIndex = -1;
        let closestTimeDiff = Infinity;
        let allEventsEnded = true;
        
        const eventsHtml = data.events.map((event, index) => {
            // Safety check for event data
            if (!event) {
                return '';
            }
            let statusClass = 'calendar-item-future';
            
            if (event.startDateTime && event.endDateTime) {
                const startTime = new Date(event.startDateTime);
                const endTime = new Date(event.endDateTime);
                
                if (endTime < now) {
                    statusClass = 'calendar-item-past';
                } else {
                    // At least one event hasn't ended yet
                    allEventsEnded = false;
                    if (startTime <= now && now <= endTime) {
                        statusClass = 'calendar-item-current';
                        currentEventIndex = index;
                    }
                }
                
                // Find closest event to current time
                const timeDiff = Math.abs(startTime - now);
                if (timeDiff < closestTimeDiff) {
                    closestTimeDiff = timeDiff;
                    closestEventIndex = index;
                }
            }
            
            return `
            <li class="calendar-item ${statusClass}" data-event-index="${index}">
                <div class="calendar-indicator"></div>
                <div class="calendar-time">${escapeHtml(event.startTime)}</div>
                <div class="calendar-details">
                    <div class="calendar-title">${escapeHtml(event.subject)}</div>
                    ${event.location ? `<div class="calendar-location">${escapeHtml(event.location)}</div>` : ''}
                </div>
            </li>
        `;
        }).join('');

        // Add message if all events have ended
        const endMessageHtml = allEventsEnded && data.events.length > 0 ? `
            <li class="calendar-item calendar-item-end-message">
                <div class="calendar-end-message-text">There are no more events today</div>
            </li>
        ` : '';

        container.innerHTML = `<ul class="calendar-list">${eventsHtml}${endMessageHtml}</ul>`;
        
        // Find the event to center (current event, or closest to now)
        try {
            const targetIndex = currentEventIndex >= 0 ? currentEventIndex : closestEventIndex;
            
            if (targetIndex >= 0 && data.events.length > 0) {
                const listElement = container.querySelector('.calendar-list');
                const targetItem = container.querySelector(`[data-event-index="${targetIndex}"]`);
                
                if (targetItem && listElement && container) {
                    // Function to center the target event
                    const centerEvent = () => {
                        try {
                            // Get the scrollable container (tile-content)
                            const scrollContainer = container;
                            if (!scrollContainer || !targetItem) return;
                            
                            const containerHeight = scrollContainer.clientHeight;
                            if (!containerHeight) return;
                            
                            // Calculate the item's position relative to the list
                            const itemOffsetTop = targetItem.offsetTop;
                            
                            // Calculate scroll position to center the item vertically
                            const scrollPosition = itemOffsetTop - (containerHeight / 2) + (targetItem.offsetHeight / 2);
                            
                            if (scrollContainer.scrollTo) {
                                scrollContainer.scrollTo({
                                    top: Math.max(0, scrollPosition),
                                    behavior: 'smooth'
                                });
                            } else {
                                scrollContainer.scrollTop = Math.max(0, scrollPosition);
                            }
                        } catch (e) {
                            console.warn('Error centering calendar event:', e);
                        }
                    };
                    
                    // Center on initial load
                    setTimeout(centerEvent, 100);
                    
                    // Scroll back to current event when mouse leaves the tile
                    const tile = container.closest('.tile');
                    if (tile) {
                        let scrollTimeout;
                        let isScrolling = false;
                        
                        // Prevent auto-scroll while user is actively scrolling
                        scrollContainer.addEventListener('scroll', () => {
                            isScrolling = true;
                            clearTimeout(scrollTimeout);
                            scrollTimeout = setTimeout(() => {
                                isScrolling = false;
                            }, 150);
                        });
                        
                        tile.addEventListener('mouseleave', () => {
                            if (!isScrolling) {
                                // Clear any pending scroll
                                clearTimeout(scrollTimeout);
                                // Scroll back after a short delay
                                scrollTimeout = setTimeout(centerEvent, 300);
                            }
                        });
                    }
                }
            }
        } catch (e) {
            console.warn('Error setting up calendar scroll:', e);
            // Continue without scroll functionality if there's an error
        }
    }

    /**
     * Render calendar heatmap tile: 5 rows (current week + 4 more), 5 weekdays per row, heat by event count.
     */
    function renderCalendarHeatmapTile(container, data) {
        if (!data.connected) {
            container.innerHTML = '<div class="tile-placeholder"><p>Connect Microsoft 365 to view calendar heat map</p><a href="/settings.php" class="tile-connect-btn">Connect Account</a></div>';
            return;
        }
        if (data.error) {
            container.innerHTML = '<div class="tile-error"><p>' + escapeHtml(data.error) + '</p><button class="tile-retry-btn" onclick="this.closest(\'.tile\').querySelector(\'.tile-refresh\').click()">Try again</button></div>';
            return;
        }
        var days = data.days || [];
        var maxCount = Math.max(1, data.maxCount || 0);
        var now = new Date();
        var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        function intensityClass(count) {
            if (count === 0) return 'calendar-heatmap-n0';
            var level = Math.min(5, Math.ceil((count / maxCount) * 5));
            return 'calendar-heatmap-n' + level;
        }
        var cellsHtml = days.map(function (d, idx) {
            var level = intensityClass(d.count);
            var isToday = d.date === todayStr;
            var todayClass = isToday ? ' calendar-heatmap-today' : '';
            var title = d.count + ' event' + (d.count !== 1 ? 's' : '') + ' on this day';
            return '<div class="calendar-heatmap-cell ' + level + todayClass + '" data-day-index="' + idx + '" title="' + escapeHtml(title) + '">' +
                '<span class="calendar-heatmap-day">' + d.day + '</span>' +
                '</div>';
        }).join('');
        container.innerHTML = '<div class="calendar-heatmap-outer">' +
            '<div class="calendar-heatmap-grid" role="grid" aria-label="Calendar heat map: event count by day (weekdays only)">' + cellsHtml + '</div>' +
            '<div class="calendar-heatmap-popover" id="calendar-heatmap-popover" aria-hidden="true"></div>' +
            '</div>';
        var grid = container.querySelector('.calendar-heatmap-grid');
        var popover = container.querySelector('.calendar-heatmap-popover');
        var hideTimeout = null;
        function formatPopoverDate(dateStr) {
            var d = new Date(dateStr + 'T12:00:00');
            return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
        }
        function showPopover(cellWrap, dayData) {
            if (hideTimeout) { clearTimeout(hideTimeout); hideTimeout = null; }
            var events = dayData.events || [];
            var dateLabel = formatPopoverDate(dayData.date);
            var content = '<div class="calendar-heatmap-popover-title">' + escapeHtml(dateLabel) + '</div>';
            if (events.length === 0) {
                content += '<p class="calendar-heatmap-popover-empty">No events</p>';
            } else {
                content += '<ul class="calendar-heatmap-popover-list">';
                for (var i = 0; i < events.length; i++) {
                    var ev = events[i];
                    content += '<li class="calendar-heatmap-popover-item"><span class="calendar-heatmap-popover-time">' + escapeHtml(ev.startTime) + '</span> ' + escapeHtml(ev.subject) + '</li>';
                }
                content += '</ul>';
            }
            popover.innerHTML = content;
            popover.setAttribute('aria-hidden', 'false');
            popover.style.left = '-9999px';
            popover.classList.add('calendar-heatmap-popover-visible');
            var rect = cellWrap.getBoundingClientRect();
            var popoverRect = popover.getBoundingClientRect();
            var top = rect.bottom + 6;
            var left = rect.left;
            if (top + popoverRect.height > window.innerHeight) top = rect.top - popoverRect.height - 6;
            if (left + popoverRect.width > window.innerWidth) left = window.innerWidth - popoverRect.width - 8;
            if (left < 8) left = 8;
            popover.style.top = top + 'px';
            popover.style.left = left + 'px';
        }
        function hidePopover() {
            hideTimeout = setTimeout(function () {
                popover.classList.remove('calendar-heatmap-popover-visible');
                popover.setAttribute('aria-hidden', 'true');
                hideTimeout = null;
            }, 150);
        }
        function cancelHide() {
            if (hideTimeout) { clearTimeout(hideTimeout); hideTimeout = null; }
        }
        grid.querySelectorAll('.calendar-heatmap-cell[data-day-index]').forEach(function (cell) {
            var idx = parseInt(cell.getAttribute('data-day-index'), 10);
            var dayData = days[idx];
            cell.addEventListener('mouseenter', function () { showPopover(cell, dayData); });
            cell.addEventListener('mouseleave', function () { hidePopover(); });
        });
        popover.addEventListener('mouseenter', cancelHide);
        popover.addEventListener('mouseleave', function () { hidePopover(); });
    }

    /**
     * Render calendar-next tile: single next event in a category (prominent)
     */
    function renderCalendarNextTile(container, data) {
        if (!data.connected) {
            container.innerHTML = '<div class="tile-placeholder"><p>Connect Microsoft 365 to view your next event</p><a href="/settings.php" class="tile-connect-btn">Connect Account</a></div>';
            return;
        }
        if (!data.configured || !data.category) {
            container.innerHTML = '<div class="tile-placeholder"><p>Set an Outlook category in Settings for this tile.</p><a href="/settings.php" class="tile-connect-btn">Settings</a></div>';
            return;
        }
        if (data.error) {
            container.innerHTML = '<div class="tile-error"><p>' + escapeHtml(data.error) + '</p><button class="tile-retry-btn" onclick="this.closest(\'.tile\').querySelector(\'.tile-refresh\').click()">Try again</button></div>';
            return;
        }
        if (!data.event) {
            container.innerHTML = '<div class="calendar-next-empty"><p class="calendar-next-empty-title">No upcoming events</p><p class="calendar-next-empty-sub">in category "' + escapeHtml(data.category) + '"</p></div>';
            return;
        }
        var e = data.event;
        var locationHtml = e.location ? '<div class="calendar-next-location">' + escapeHtml(e.location) + '</div>' : '';
        var daysLabel = formatDaysUntil(e.startDateTime);
        var mainDateLabel = e.startDate + (e.startTime && e.startTime !== 'All Day' ? ' · ' + e.startTime : '');
        var secondHtml = '';
        if (data.eventNext) {
            var e2 = data.eventNext;
            var secondDateLabel = e2.startDate + (e2.startTime && e2.startTime !== 'All Day' ? ' · ' + e2.startTime : '');
            secondHtml = '<div class="calendar-next-second">' +
                '<div class="calendar-next-second-date">' + escapeHtml(secondDateLabel) + '</div>' +
                '<div class="calendar-next-second-subject">' + escapeHtml(e2.subject) + '</div>' +
                '</div>';
        }
        container.innerHTML = '<div class="calendar-next-card">' +
            '<div class="calendar-next-countdown">' + escapeHtml(daysLabel) + '</div>' +
            '<div class="calendar-next-date">' + escapeHtml(mainDateLabel) + '</div>' +
            '<h4 class="calendar-next-subject">' + escapeHtml(e.subject) + '</h4>' +
            locationHtml + '</div>' + secondHtml;
    }

    function renderNextEventTile(container, data) {
        if (!data.connected) {
            container.innerHTML = '<div class="tile-placeholder">' +
                '<p>Connect Microsoft 365 to view your next event</p>' +
                '<a href="/settings.php" class="tile-connect-btn">Connect Account</a></div>';
            return;
        }
        if (data.error) {
            container.innerHTML = '<div class="tile-error"><p>' + escapeHtml(data.error) + '</p>' +
                '<button class="tile-retry-btn" onclick="this.closest(\'.tile\').querySelector(\'.tile-refresh\').click()">Try again</button></div>';
            return;
        }
        if (!data.event) {
            container.innerHTML = '<div class="calendar-next-empty">' +
                '<p class="calendar-next-empty-title">No upcoming events</p>' +
                '<p class="calendar-next-empty-sub">Your next calendar event will appear here</p></div>';
            return;
        }
        var e = data.event;
        var locationHtml = e.location ? '<div class="calendar-next-location">' + escapeHtml(e.location) + '</div>' : '';
        var dateTimeLabel = e.startDate + (e.startTime && e.startTime !== 'All Day' ? ' · ' + e.startTime : '');
        var now = new Date();
        var startMs = e.startDateTime ? new Date(e.startDateTime).getTime() : 0;
        var endMs = e.endDateTime ? new Date(e.endDateTime).getTime() : 0;
        var isInProgress = startMs > 0 && endMs > 0 && now >= startMs && now < endMs;
        var inProgressClass = isInProgress ? ' in-progress' : '';
        container.innerHTML = '<div class="next-event-card">' +
            '<div class="next-event-countdown' + inProgressClass + '" data-start-datetime="' + escapeHtml(e.startDateTime || '') + '" data-end-datetime="' + escapeHtml(e.endDateTime || '') + '">' + escapeHtml(formatCountdown(e.startDateTime)) + '</div>' +
            '<div class="next-event-datetime">' + escapeHtml(dateTimeLabel) + '</div>' +
            '<h4 class="next-event-subject">' + escapeHtml(e.subject) + '</h4>' +
            locationHtml + '</div>';
        var countdownEl = container.querySelector('.next-event-countdown');
        if (countdownEl && e.startDateTime) {
            var t;
            function tick() {
                if (!countdownEl.isConnected) {
                    clearInterval(t);
                    return;
                }
                var now = new Date();
                var startMs = e.startDateTime ? new Date(e.startDateTime).getTime() : 0;
                var endMs = e.endDateTime ? new Date(e.endDateTime).getTime() : 0;
                var isInProgress = startMs > 0 && endMs > 0 && now >= startMs && now < endMs;
                if (isInProgress) {
                    countdownEl.classList.add('in-progress');
                } else {
                    countdownEl.classList.remove('in-progress');
                }
                var text = formatCountdown(e.startDateTime);
                if (countdownEl.textContent !== text) countdownEl.textContent = text;
            }
            tick();
            var intervalMs = (startMs - Date.now()) < 24 * 60 * 60 * 1000 ? 1000 : 60000;
            t = setInterval(tick, intervalMs);
        }
    }

    /**
     * Render availability tile: show available meeting slots with copy-to-clipboard button
     */
    function renderAvailabilityTile(container, data) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect Microsoft 365 to view availability</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (data.error) {
            container.innerHTML = `
                <div class="tile-error">
                    <p>${escapeHtml(data.error)}</p>
                    <button class="tile-retry-btn" onclick="this.closest('.tile').querySelector('.tile-refresh').click()">Try again</button>
                </div>
            `;
            return;
        }

        if (!data.slots || data.slots.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="empty-state-text">No available slots found</p>
                    <p class="empty-state-subtext">Your calendar appears to be fully booked</p>
                </div>
            `;
            return;
        }

        const slotsHtml = data.slots.map(slot => `
            <div class="availability-tile-square">
                <div class="availability-tile-day">${escapeHtml(slot.dayNumber || '')}</div>
                <div class="availability-tile-month">${escapeHtml(slot.monthAbbr || '')}</div>
                <div class="availability-tile-time">${escapeHtml(slot.timeRange || slot.time || '')}</div>
            </div>
        `).join('');

        container.innerHTML = `
            <div class="availability-content">
                <button class="availability-copy-btn" data-copy-text="${escapeHtml(data.text)}" title="Copy to clipboard">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span>Copy</span>
                </button>
                <div class="availability-tiles-grid">
                    ${slotsHtml}
                </div>
            </div>
        `;

        // Add click handler for copy button
        const copyBtn = container.querySelector('.availability-copy-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const textToCopy = this.dataset.copyText || '';
                if (!textToCopy) return;

                try {
                    await navigator.clipboard.writeText(textToCopy);
                    
                    // Show feedback
                    const originalText = this.querySelector('span').textContent;
                    this.querySelector('span').textContent = 'Copied!';
                    this.classList.add('copied');
                    
                    setTimeout(() => {
                        this.querySelector('span').textContent = originalText;
                        this.classList.remove('copied');
                    }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = textToCopy;
                    textArea.style.position = 'fixed';
                    textArea.style.opacity = '0';
                    document.body.appendChild(textArea);
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        const originalText = this.querySelector('span').textContent;
                        this.querySelector('span').textContent = 'Copied!';
                        this.classList.add('copied');
                        setTimeout(() => {
                            this.querySelector('span').textContent = originalText;
                            this.classList.remove('copied');
                        }, 2000);
                    } catch (fallbackErr) {
                        console.error('Fallback copy failed:', fallbackErr);
                        alert('Failed to copy. Please select and copy manually.');
                    }
                    document.body.removeChild(textArea);
                }
            });
        }
    }

    /**
     * Format countdown string to event start (e.g. "in 2h 15m 30s", "Starting now", "5 days")
     */
    function formatCountdown(isoDateTime) {
        if (!isoDateTime) return '';
        var start = new Date(isoDateTime);
        var now = new Date();
        var diffMs = start - now;
        if (diffMs < 0) {
            var mins = Math.floor(-diffMs / 60000);
            if (mins < 1) return 'Started';
            if (mins < 60) return 'Started ' + mins + 'm ago';
            var hours = Math.floor(mins / 60);
            return 'Started ' + hours + 'h ago';
        }
        if (diffMs < 60000) return 'Starting now';
        var totalSec = Math.floor(diffMs / 1000);
        var sec = totalSec % 60;
        var totalMin = Math.floor(totalSec / 60);
        var min = totalMin % 60;
        var hours = Math.floor(totalMin / 60);
        var days = Math.floor(hours / 24);
        if (days > 0) {
            var h = hours % 24;
            if (h === 0) return days === 1 ? '1 day' : days + ' days';
            return days + 'd ' + h + 'h';
        }
        if (hours > 0) return hours + 'h ' + min + 'm ' + sec + 's';
        if (min > 0) return min + 'm ' + sec + 's';
        return sec + 's';
    }

    function formatDaysUntil(isoDateTime) {
        if (!isoDateTime) return '';
        var start = new Date(isoDateTime);
        var now = new Date();
        var startDay = new Date(start.getFullYear(), start.getMonth(), start.getDate());
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var diffMs = startDay - today;
        var diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));
        if (diffDays < 0) return 'Past';
        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return '1 day';
        return diffDays + ' days';
    }

    /**
     * Render todo/tasks tile
     */
    function renderTodoTile(container, data, tileElement = null) {
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
            const emptySource = data.tasks_source_label ? ` <span class="task-list-source">(${escapeHtml(data.tasks_source_label)})</span>` : '';
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    <p class="empty-state-text">No tasks${emptySource}</p>
                </div>
            `;
            return;
        }

        const tasksHtml = data.tasks.map(task => {
            const overdueStyle = getOverdueBackgroundStyle(task.dueDateTime, task.completed);
            return `
            <li class="task-item task-item-clickable ${task.completed ? 'completed' : ''}"
                data-task-id="${escapeHtml(task.id)}"
                data-task-title="${escapeHtml(task.title)}"
                data-task-importance="${escapeHtml(task.importance || 'normal')}"
                data-task-due-date="${escapeHtml(task.dueDate || '')}"
                data-task-source="${escapeHtml(task.source || 'todo')}"
                ${task.list_id ? `data-task-list-id="${escapeHtml(task.list_id)}"` : ''}
                ${overdueStyle ? `style="${overdueStyle.replace(/"/g, '&quot;')}"` : ''}>
                <div class="task-checkbox ${task.completed ? 'completed' : ''}" data-task-id="${task.id}"></div>
                <div class="task-content">
                    <div class="task-title">
                        ${task.importance === 'high' ? '<span class="task-priority high"></span>' : ''}
                        ${escapeHtml(task.title)}
                    </div>
                    ${task.dueDate ? `<div class="task-meta">Due: ${escapeHtml(task.dueDate)}</div>` : ''}
                </div>
            </li>
        `; }).join('');

        const sourceLabel = data.tasks_source_label ? `<p class="task-list-source">Showing: ${escapeHtml(data.tasks_source_label)}</p>` : '';
        container.innerHTML = `<ul class="task-list">${tasksHtml}</ul>${sourceLabel}<div class="tile-content-bottom-pad" aria-hidden="true"></div>`;

        const tile = tileElement || container.closest('.tile');
        if (tile) {
            tile.dataset.tasksSource = data.tasks_source || 'all_incomplete';
            tile.dataset.tasksListId = data.tasks_list_id || '';
            tile.dataset.tasksSourceLabel = data.tasks_source_label || '';
        }

        container.querySelectorAll('.task-checkbox').forEach(checkbox => {
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation();
                const taskId = this.dataset.taskId;
                if (!taskId) return;
                const taskItem = this.closest('.task-item');
                const source = (taskItem && taskItem.dataset.taskSource) || 'todo';
                const listId = (taskItem && taskItem.dataset.taskListId) || '';
                const body = { task_id: taskId, source: source };
                if (source === 'todo' && listId) body.list_id = listId;
                fetch(CONFIG.tasksEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(body)
                }).then(r => {
                    if (!r.ok) return r.json().then(d => Promise.reject(new Error(d.error || 'Failed to update task')));
                    return r.json();
                }).then(() => {
                    if (tile) loadTileData(tile, false);
                }).catch(err => {
                    console.error('Task complete error:', err);
                    alert(err.message || 'Failed to mark task complete.');
                });
            });
        });

        container.querySelectorAll('.task-item-clickable').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.closest('.task-checkbox')) return;
                const task = {
                    id: this.dataset.taskId,
                    title: this.dataset.taskTitle || '(No Title)',
                    importance: this.dataset.taskImportance || 'normal',
                    dueDate: this.dataset.taskDueDate || null,
                    source: this.dataset.taskSource || 'todo',
                    listId: this.dataset.taskListId || null,
                    listName: (tile && tile.dataset.tasksSourceLabel) || null
                };
                openTaskDetailOverlay(task, tile);
            });
        });
    }

    /**
     * Render Planner overview tile: tasks assigned to me, grouped by plan in columns.
     */
    function renderPlannerOverviewTile(container, data, tileElement = null) {
        if (!data.connected) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Connect Microsoft 365 to view Planner tasks</p>
                    <a href="/settings.php" class="tile-connect-btn">Connect Account</a>
                </div>
            `;
            return;
        }

        if (!data.plans || data.plans.length === 0) {
            const message = data.planner_unavailable
                ? 'Planner is not available for this account.'
                : (data.error || 'No outstanding Planner tasks');
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    <p class="empty-state-text">${escapeHtml(message)}</p>
                </div>
            `;
            return;
        }

        const columnsHtml = data.plans.map((plan, index) => {
            const tasksHtml = (plan.tasks || []).map(task => {
                const overdueStyle = getOverdueBackgroundStyle(task.dueDateTime, false);
                const pct = task.percentComplete != null ? Number(task.percentComplete) : '';
                return `
                <li class="task-item task-item-clickable"
                    data-task-id="${escapeHtml(task.id)}"
                    data-task-title="${escapeHtml(task.title)}"
                    data-task-importance="${escapeHtml(task.importance || 'normal')}"
                    data-task-due-date="${escapeHtml(task.dueDate || '')}"
                    data-task-source="planner"
                    data-task-plan-name="${escapeHtml(plan.title)}"
                    data-task-percent-complete="${escapeHtml(String(pct))}"
                    ${overdueStyle ? `style="${escapeHtml(overdueStyle)}"` : ''}>
                    <div class="task-checkbox" data-task-id="${task.id}"></div>
                    <div class="task-content">
                        <div class="task-title">
                            ${task.importance === 'high' ? '<span class="task-priority high"></span>' : ''}
                            ${escapeHtml(task.title)}
                        </div>
                        ${task.dueDate ? `<div class="task-meta">Due: ${escapeHtml(task.dueDate)}</div>` : ''}
                    </div>
                </li>
            `; }).join('');
            const taskCount = (plan.tasks || []).length;
            return `
                <div class="planner-overview-column" data-column-index="${index}">
                    <h4 class="planner-overview-column-title">${escapeHtml(plan.title)}<span class="planner-overview-column-count"> (${taskCount})</span></h4>
                    <ul class="task-list">${tasksHtml}</ul>
                </div>
            `;
        }).join('');

        const totalIncomplete = typeof data.total_incomplete === 'number' ? data.total_incomplete : data.plans.reduce((sum, plan) => sum + (plan.tasks || []).length, 0);
        const shownCount = data.plans.reduce((sum, plan) => sum + (plan.tasks || []).length, 0);
        const showingLine = totalIncomplete > shownCount ? `<p class="planner-overview-total-sub">Showing ${shownCount} of ${totalIncomplete} tasks</p>` : '';
        const totalHeaderHtml = `<div class="planner-overview-total"><span class="planner-overview-total-number">${totalIncomplete}</span>${showingLine}</div>`;

        const hasHidden = (data.hidden_plans && data.hidden_plans.length > 0);
        const showJumper = data.plans.length >= 1;
        function buildPopoverHtml(currentPlans, hiddenPlans) {
            const shownPart = currentPlans.length ? `
                <div class="planner-overview-jumper-section">
                    <div class="planner-overview-jumper-section-title">In view</div>
                    ${currentPlans.map((plan, index) => `<button type="button" role="option" class="planner-overview-jumper-option" data-column-index="${index}">${escapeHtml(plan.title)}</button>`).join('')}
                </div>
            ` : '';
            const hiddenPart = hiddenPlans.length ? `
                <div class="planner-overview-jumper-section">
                    <div class="planner-overview-jumper-section-title">Not shown</div>
                    ${hiddenPlans.map(h => `<button type="button" role="option" class="planner-overview-jumper-option planner-overview-jumper-option-hidden" data-plan-id="${escapeHtml(h.id)}" data-plan-title="${escapeHtml(h.title)}">${escapeHtml(h.title)}</button>`).join('')}
                </div>
            ` : '';
            return (shownPart + hiddenPart).trim() || '<p class="planner-overview-jumper-empty">No plans</p>';
        }

        const jumperHtml = showJumper ? `
            <div class="planner-overview-jumper">
                <button type="button" class="planner-overview-jumper-btn" aria-expanded="false" aria-haspopup="true" aria-label="Jump to plan" title="Jump to plan">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <span>Plans</span>
                </button>
                <div class="planner-overview-jumper-popover" role="listbox" aria-label="Plans">
                    ${buildPopoverHtml(data.plans, data.hidden_plans || [])}
                </div>
            </div>
        ` : '';

        const topRowHtml = `<div class="planner-overview-top-row">${totalHeaderHtml}${jumperHtml}</div>`;
        container.innerHTML = `<div class="planner-overview-wrap">${topRowHtml}<div class="planner-overview-columns">${columnsHtml}</div></div><div class="tile-content-bottom-pad" aria-hidden="true"></div>`;

        const tile = tileElement || container.closest('.tile');

        if (showJumper) {
            let currentPlans = data.plans.map(p => ({ id: p.id, title: p.title, tasks: p.tasks ? p.tasks.slice() : [] }));
            let hiddenPlans = (data.hidden_plans || []).map(h => ({ id: h.id, title: h.title }));

            const jumper = container.querySelector('.planner-overview-jumper');
            const jumperBtn = container.querySelector('.planner-overview-jumper-btn');
            const popover = container.querySelector('.planner-overview-jumper-popover');
            const columnsEl = container.querySelector('.planner-overview-columns');
            if (!jumper || !jumperBtn || !popover) return;

            function buildColumnHtml(plan, index) {
                const tasksHtml = (plan.tasks || []).map(task => {
                    const overdueStyle = getOverdueBackgroundStyle(task.dueDateTime, false);
                    const pct = task.percentComplete != null ? Number(task.percentComplete) : '';
                    return `
                    <li class="task-item task-item-clickable"
                        data-task-id="${escapeHtml(task.id)}"
                        data-task-title="${escapeHtml(task.title)}"
                        data-task-importance="${escapeHtml(task.importance || 'normal')}"
                        data-task-due-date="${escapeHtml(task.dueDate || '')}"
                        data-task-source="planner"
                        data-task-plan-name="${escapeHtml(plan.title)}"
                        data-task-percent-complete="${escapeHtml(String(pct))}"
                        ${overdueStyle ? `style="${overdueStyle.replace(/"/g, '&quot;')}"` : ''}>
                        <div class="task-checkbox" data-task-id="${task.id}"></div>
                        <div class="task-content">
                            <div class="task-title">
                                ${task.importance === 'high' ? '<span class="task-priority high"></span>' : ''}
                                ${escapeHtml(task.title)}
                            </div>
                            ${task.dueDate ? `<div class="task-meta">Due: ${escapeHtml(task.dueDate)}</div>` : ''}
                        </div>
                    </li>
                `; }).join('');
                const taskCount = (plan.tasks || []).length;
                return `
                    <div class="planner-overview-column" data-column-index="${index}">
                        <h4 class="planner-overview-column-title">${escapeHtml(plan.title)}<span class="planner-overview-column-count"> (${taskCount})</span></h4>
                        <ul class="task-list">${tasksHtml}</ul>
                    </div>
                `;
            }

            function attachColumnHandlers(colEl) {
                if (!colEl) return;
                (colEl.querySelectorAll('.task-checkbox') || []).forEach(checkbox => {
                    checkbox.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const taskId = this.dataset.taskId;
                        if (!taskId) return;
                        const taskItem = this.closest('.task-item');
                        const source = (taskItem && taskItem.dataset.taskSource) || 'planner';
                        fetch(CONFIG.tasksEndpoint, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CONFIG.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ task_id: taskId, source: source })
                        }).then(r => {
                            if (!r.ok) return r.json().then(d => Promise.reject(new Error(d.error || 'Failed to update task')));
                            return r.json();
                        }).then(() => { if (tile) loadTileData(tile, false); }).catch(err => { console.error('Task complete error:', err); alert(err.message || 'Failed to mark task complete.'); });
                    });
                });
                (colEl.querySelectorAll('.task-item-clickable') || []).forEach(item => {
                    item.addEventListener('click', function(e) {
                        if (e.target.closest('.task-checkbox')) return;
                        const pctRaw = this.dataset.taskPercentComplete;
                        const percentComplete = (pctRaw !== undefined && pctRaw !== '') ? parseInt(pctRaw, 10) : null;
                        openTaskDetailOverlay({
                            id: this.dataset.taskId,
                            title: this.dataset.taskTitle || '(No Title)',
                            importance: this.dataset.taskImportance || 'normal',
                            dueDate: this.dataset.taskDueDate || null,
                            source: 'planner',
                            planName: this.dataset.taskPlanName || null,
                            percentComplete: isNaN(percentComplete) ? null : percentComplete
                        }, tile);
                    });
                });
            }

            function closePopover() {
                if (popover && popover.classList.contains('is-open')) {
                    popover.classList.remove('is-open');
                    jumperBtn.setAttribute('aria-expanded', 'false');
                }
            }

            function rebuildPopover() {
                popover.innerHTML = buildPopoverHtml(currentPlans, hiddenPlans);
                popover.querySelectorAll('button[data-column-index]').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const index = this.getAttribute('data-column-index');
                        const col = columnsEl.querySelector('.planner-overview-column[data-column-index="' + index + '"]');
                        if (col) col.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
                        closePopover();
                    });
                });
            }

            popover.addEventListener('click', function(e) {
                const btn = e.target.closest('button.planner-overview-jumper-option-hidden');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                const planId = (btn.getAttribute && btn.getAttribute('data-plan-id')) || btn.dataset.planId || '';
                if (!planId) return;
                btn.disabled = true;
                const tileId = tile ? (parseInt(tile.dataset.tileId) || 0) : 0;
                fetch(CONFIG.apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CONFIG.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ type: 'planner-overview', tile_id: tileId, plan_id: planId })
                }).then(r => {
                    if (!r.ok) return r.json().then(d => Promise.reject(new Error(d.error || 'Failed to load plan')));
                    return r.json();
                }).then(res => {
                    if (res.error || !res.plan) throw new Error(res.error || 'Invalid response');
                    const lastIndex = currentPlans.length - 1;
                    const oldPlan = currentPlans[lastIndex];
                    currentPlans[lastIndex] = { id: res.plan.id, title: res.plan.title, tasks: res.plan.tasks || [] };
                    hiddenPlans = hiddenPlans.filter(h => h.id !== planId);
                    if (oldPlan && oldPlan.id) hiddenPlans.push({ id: oldPlan.id, title: oldPlan.title });
                    const lastCol = columnsEl.querySelector('.planner-overview-column[data-column-index="' + lastIndex + '"]');
                    if (lastCol) {
                        lastCol.outerHTML = buildColumnHtml(currentPlans[lastIndex], lastIndex);
                        attachColumnHandlers(columnsEl.querySelector('.planner-overview-column[data-column-index="' + lastIndex + '"]'));
                    }
                    rebuildPopover();
                }).catch(err => {
                    console.error('Load plan error:', err);
                    alert(err.message || 'Failed to load plan.');
                }).finally(() => { btn.disabled = false; });
            });

            jumperBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = popover.classList.toggle('is-open');
                jumperBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            rebuildPopover();

            function outsideClick(e) {
                if (!popover.isConnected) {
                    document.removeEventListener('click', outsideClick);
                    document.removeEventListener('keydown', escapeClose);
                    return;
                }
                if (popover.classList.contains('is-open') && jumper && !jumper.contains(e.target)) {
                    closePopover();
                }
            }
            function escapeClose(e) {
                if (!popover.isConnected) {
                    document.removeEventListener('click', outsideClick);
                    document.removeEventListener('keydown', escapeClose);
                    return;
                }
                if (e.key === 'Escape' && popover.classList.contains('is-open')) {
                    closePopover();
                }
            }
            document.addEventListener('click', outsideClick);
            document.addEventListener('keydown', escapeClose);
        }

        container.querySelectorAll('.task-checkbox').forEach(checkbox => {
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation();
                const taskId = this.dataset.taskId;
                if (!taskId) return;
                const taskItem = this.closest('.task-item');
                const source = (taskItem && taskItem.dataset.taskSource) || 'planner';
                const body = { task_id: taskId, source: source };
                fetch(CONFIG.tasksEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(body)
                }).then(r => {
                    if (!r.ok) return r.json().then(d => Promise.reject(new Error(d.error || 'Failed to update task')));
                    return r.json();
                }).then(() => {
                    if (tile) loadTileData(tile, false);
                }).catch(err => {
                    console.error('Task complete error:', err);
                    alert(err.message || 'Failed to mark task complete.');
                });
            });
        });

        container.querySelectorAll('.task-item-clickable').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.closest('.task-checkbox')) return;
                const pctRaw = this.dataset.taskPercentComplete;
                const percentComplete = (pctRaw !== undefined && pctRaw !== '') ? parseInt(pctRaw, 10) : null;
                const task = {
                    id: this.dataset.taskId,
                    title: this.dataset.taskTitle || '(No Title)',
                    importance: this.dataset.taskImportance || 'normal',
                    dueDate: this.dataset.taskDueDate || null,
                    source: 'planner',
                    planName: this.dataset.taskPlanName || null,
                    percentComplete: isNaN(percentComplete) ? null : percentComplete
                };
                openTaskDetailOverlay(task, tile);
            });
        });
    }

    /**
     * Open task detail overlay (same blurred-background style as notes popup)
     */
    function openTaskDetailOverlay(task, tileElement) {
        let overlay = document.getElementById('task-detail-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'task-detail-overlay';
            overlay.id = 'task-detail-overlay';
            overlay.innerHTML = `
                <div class="task-detail-modal">
                    <div class="task-detail-modal-header">
                        <h3 class="task-detail-modal-title">Task details</h3>
                        <button type="button" class="task-detail-modal-close" id="task-detail-close-btn" title="Close">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="task-detail-modal-body">
                        <p class="task-detail-task-title" id="task-detail-task-title"></p>
                        <div class="task-detail-meta" id="task-detail-meta"></div>
                        <div class="task-detail-actions" id="task-detail-actions"></div>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            document.getElementById('task-detail-close-btn').addEventListener('click', closeTaskDetailOverlay);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeTaskDetailOverlay();
            });
            document.addEventListener('keydown', function taskDetailEscape(e) {
                if (e.key === 'Escape' && document.getElementById('task-detail-overlay')?.classList.contains('show')) {
                    closeTaskDetailOverlay();
                }
            });
        }

        document.getElementById('task-detail-task-title').textContent = task.title;
        const metaEl = document.getElementById('task-detail-meta');
        const importanceLabel = { high: 'High', normal: 'Normal', low: 'Low' }[task.importance] || task.importance;
        const locationRow = task.listName
            ? `<div class="task-detail-meta-row"><span class="task-detail-meta-label">List</span><span>${escapeHtml(task.listName)}</span></div>`
            : (task.planName ? `<div class="task-detail-meta-row"><span class="task-detail-meta-label">Plan</span><span>${escapeHtml(task.planName)}</span></div>` : '');
        const progressRow = (task.percentComplete != null && task.percentComplete < 100)
            ? `<div class="task-detail-meta-row"><span class="task-detail-meta-label">Progress</span><span>${escapeHtml(String(task.percentComplete))}%</span></div>`
            : '';
        metaEl.innerHTML = `
            ${locationRow}
            <div class="task-detail-meta-row">
                <span class="task-detail-meta-label">Priority</span>
                <span class="task-detail-priority ${task.importance}">${escapeHtml(importanceLabel)}</span>
            </div>
            ${task.dueDate ? `
            <div class="task-detail-meta-row">
                <span class="task-detail-meta-label">Due</span>
                <span>${escapeHtml(task.dueDate)}</span>
            </div>
            ` : ''}
            ${progressRow}
        `;

        const actionsEl = document.getElementById('task-detail-actions');
        const source = task.source || (task.planName ? 'planner' : 'todo');
        const canComplete = source === 'todo' || source === 'planner';
        if (canComplete && actionsEl) {
            actionsEl.innerHTML = '<button type="button" class="task-detail-mark-complete" id="task-detail-mark-complete-btn">Mark complete</button>';
            const btn = document.getElementById('task-detail-mark-complete-btn');
            const tile = tileElement || null;
            btn.addEventListener('click', function() {
                const body = { task_id: task.id, source: source };
                if (source === 'todo' && task.listId) body.list_id = task.listId;
                btn.disabled = true;
                fetch(CONFIG.tasksEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CONFIG.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body)
                }).then(r => {
                    if (!r.ok) return r.json().then(d => Promise.reject(new Error(d.error || 'Failed to update task')));
                    return r.json();
                }).then(() => {
                    closeTaskDetailOverlay();
                    if (tile) loadTileData(tile, false);
                }).catch(err => {
                    console.error('Task complete error:', err);
                    alert(err.message || 'Failed to mark task complete.');
                }).finally(() => { btn.disabled = false; });
            });
        } else if (actionsEl) {
            actionsEl.innerHTML = '';
        }

        requestAnimationFrame(() => overlay.classList.add('show'));
    }

    /**
     * Close task detail overlay
     */
    function closeTaskDetailOverlay() {
        const overlay = document.getElementById('task-detail-overlay');
        if (!overlay) return;
        overlay.classList.remove('show');
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
            <li class="crm-item crm-item-clickable"
                data-crm-contact="${escapeHtml(action.contactName)}"
                data-crm-action="${escapeHtml(action.actionText)}"
                data-crm-due="${escapeHtml(action.dueDate || '')}"
                data-crm-due-full="${escapeHtml(action.dueDateFull || action.dueDate || 'No date set')}"
                data-crm-overdue="${action.isOverdue ? '1' : '0'}"
                data-crm-status="${escapeHtml(action.statusLabel || '')}">
                <div class="crm-contact">${escapeHtml(action.contactName)}</div>
                <div class="crm-action">${escapeHtml(action.actionText)}</div>
                <div class="crm-due ${action.isOverdue ? 'overdue' : ''}">
                    ${action.isOverdue ? 'Overdue: ' : 'Due: '}${escapeHtml(action.dueDate)}
                </div>
            </li>
        `).join('');

        container.innerHTML = `<ul class="crm-list">${actionsHtml}</ul><div class="tile-content-bottom-pad" aria-hidden="true"></div>`;

        container.querySelectorAll('.crm-item-clickable').forEach(function(item) {
            item.addEventListener('click', function() {
                const action = {
                    contactName: this.dataset.crmContact || '',
                    actionText: this.dataset.crmAction || '',
                    dueDate: this.dataset.crmDue || null,
                    dueDateFull: this.dataset.crmDueFull || this.dataset.crmDue || 'No date set',
                    isOverdue: this.dataset.crmOverdue === '1',
                    statusLabel: this.dataset.crmStatus || ''
                };
                openCrmDetailOverlay(action);
            });
        });
    }

    /**
     * Open CRM action detail overlay
     */
    function openCrmDetailOverlay(action) {
        let overlay = document.getElementById('crm-detail-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'crm-detail-overlay';
            overlay.id = 'crm-detail-overlay';
            overlay.innerHTML = `
                <div class="crm-detail-modal">
                    <div class="crm-detail-modal-header">
                        <h3 class="crm-detail-modal-title">Action details</h3>
                        <button type="button" class="crm-detail-modal-close" id="crm-detail-close-btn" title="Close">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="crm-detail-modal-body">
                        <p class="crm-detail-action-title" id="crm-detail-action-title"></p>
                        <div class="crm-detail-meta" id="crm-detail-meta"></div>
                        <p class="crm-detail-hint">View and complete this action in OnePageCRM.</p>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            document.getElementById('crm-detail-close-btn').addEventListener('click', closeCrmDetailOverlay);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeCrmDetailOverlay();
            });
            document.addEventListener('keydown', function crmDetailEscape(e) {
                if (e.key === 'Escape' && document.getElementById('crm-detail-overlay') && document.getElementById('crm-detail-overlay').classList.contains('show')) {
                    closeCrmDetailOverlay();
                }
            });
        }

        document.getElementById('crm-detail-action-title').textContent = action.actionText || 'Action';
        const metaEl = document.getElementById('crm-detail-meta');
        metaEl.innerHTML = `
            <div class="crm-detail-meta-row">
                <span class="crm-detail-meta-label">Contact</span>
                <span>${escapeHtml(action.contactName || '—')}</span>
            </div>
            <div class="crm-detail-meta-row">
                <span class="crm-detail-meta-label">Due</span>
                <span class="${action.isOverdue ? 'crm-detail-overdue' : ''}">${escapeHtml(action.dueDateFull && action.dueDateFull !== 'No date set' ? action.dueDateFull : (action.dueDate || 'No date set'))}</span>
            </div>
            ${action.statusLabel ? `
            <div class="crm-detail-meta-row">
                <span class="crm-detail-meta-label">Status</span>
                <span>${escapeHtml(action.statusLabel)}</span>
            </div>
            ` : ''}
        `;

        requestAnimationFrame(function() { overlay.classList.add('show'); });
    }

    /**
     * Close CRM action detail overlay
     */
    function closeCrmDetailOverlay() {
        const overlay = document.getElementById('crm-detail-overlay');
        if (overlay) overlay.classList.remove('show');
    }

    /**
     * Build HTML for one direction's route header + departures list (for train tile).
     */
    function buildTrainDirectionHtml(origin, destination, departures) {
        const routeHeader = `
            <div class="train-route-header">
                <span class="train-station-code">${escapeHtml(origin.crs || '')}</span>
                <svg class="train-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
                <span class="train-station-code">${escapeHtml(destination.crs || '')}</span>
            </div>
        `;
        const departuresHtml = (departures || []).map(dep => {
            const statusClass = dep.isCancelled ? 'train-status-cancelled' : dep.isDelayed ? 'train-status-delayed' : 'train-status-ontime';
            const statusIcon = dep.isCancelled ? '✕' : dep.isDelayed ? '⚠' : '✓';
            const platformPart = dep.platform ? `Plat ${escapeHtml(dep.platform)}` : '';
            const isActualTime = dep.expectedDisplay && /^\d{1,2}:\d{2}$/.test(dep.expectedDisplay);
            const expectedPart = isActualTime && dep.expectedDisplay !== dep.scheduledDisplay && !dep.isCancelled ? ` (${escapeHtml(dep.expectedDisplay)})` : '';
            const destinationName = dep.destination && dep.destination.length > 0 ? (dep.destination[0].locationName || dep.destination[0].crs || '') : '';
            return `
                <div class="train-departure-item">
                    <span class="train-time">${escapeHtml(dep.scheduledDisplay)}${expectedPart}</span>
                    <span class="train-status ${statusClass}">${statusIcon} ${escapeHtml(dep.status)}</span>
                    ${platformPart ? `<span class="train-platform">${platformPart}</span>` : ''}
                    ${destinationName ? `<span class="train-destination">${escapeHtml(destinationName)}</span>` : ''}
                </div>
            `;
        }).join('');
        return `${routeHeader}<div class="train-departures-list">${departuresHtml}</div>`;
    }

    /**
     * Render train departures tile (supports both directions + flip button when API returns direction_ab/direction_ba).
     */
    /**
     * Calculate minutes until next departure
     * Returns null if no valid departure found
     */
    function getNextDepartureMinutes(departures) {
        if (!departures || departures.length === 0) return null;
        
        const now = new Date();
        const currentHour = now.getHours();
        const currentMinute = now.getMinutes();
        const currentTimeMinutes = currentHour * 60 + currentMinute;
        
        // Late night: after 22:00 (10pm) - next departure could be tomorrow morning
        const isLateNight = currentTimeMinutes >= 22 * 60;
        // Early morning: before 04:00 (4am) - treat as "tomorrow" for rollover
        const isEarlyMorning = currentTimeMinutes < 4 * 60;
        
        let firstPastDeparture = null; // First dep whose displayed time is "past" today (for late-night fallback)
        
        for (const dep of departures) {
            if (dep.isCancelled) continue;
            
            // Use expectedDisplay if it's a valid time (HH:MM), otherwise scheduledDisplay
            let timeDisplay = dep.expectedDisplay;
            if (!timeDisplay || timeDisplay === 'Delayed' || timeDisplay === 'On time' || timeDisplay === 'Cancelled' || !/^\d{1,2}:\d{2}$/.test(timeDisplay)) {
                timeDisplay = dep.scheduledDisplay;
            }
            
            if (!timeDisplay || !/^\d{1,2}:\d{2}$/.test(timeDisplay)) {
                continue;
            }
            
            const timeMatch = timeDisplay.match(/^(\d{1,2}):(\d{2})/);
            if (!timeMatch) continue;
            
            const hours = parseInt(timeMatch[1], 10);
            const minutes = parseInt(timeMatch[2], 10);
            const depTimeMinutes = hours * 60 + minutes;
            
            if (depTimeMinutes > currentTimeMinutes) {
                // Same day, time is in the future - this is the next departure
                const diffMins = depTimeMinutes - currentTimeMinutes;
                if (diffMins < 1440) return diffMins;
            }
            
            // Displayed time is "past" today: usually means train already left (board not updated yet).
            // Don't assume "tomorrow" or we get 1000+ minutes. Remember for late-night rollover only.
            if (firstPastDeparture === null) {
                firstPastDeparture = { depTimeMinutes };
            }
        }
        
        // All listed times were in the past. Only use "tomorrow" for real rollover (late night -> early morning).
        if (firstPastDeparture && isLateNight) {
            const depTimeMinutes = firstPastDeparture.depTimeMinutes;
            if (depTimeMinutes < 8 * 60) { // Departure before 08:00 = next day
                const diffMins = (1440 - currentTimeMinutes) + depTimeMinutes;
                if (diffMins >= 0 && diffMins < 1440) return diffMins;
            }
        }
        
        if (firstPastDeparture && isEarlyMorning) {
            const depTimeMinutes = firstPastDeparture.depTimeMinutes;
            const diffMins = (1440 - currentTimeMinutes) + depTimeMinutes;
            if (diffMins >= 0 && diffMins < 1440) return diffMins;
        }
        
        // First departure in list has a "past" time but it's not late night - train likely just left or board is stale. Show 0.
        if (firstPastDeparture) {
            return 0;
        }
        
        return null;
    }

    /**
     * Update countdown display for train tile
     */
    function updateTrainCountdown(wrapper) {
        if (!wrapper || !wrapper._trainTileData) return;
        
        const currentDir = wrapper.getAttribute('data-train-direction') || 'ab';
        const dirData = currentDir === 'ab' ? wrapper._trainTileData.direction_ab : wrapper._trainTileData.direction_ba;
        if (!dirData) return;
        
        const deps = dirData.departures || [];
        const minutes = getNextDepartureMinutes(deps);
        
        const countdownEl = wrapper.querySelector('.train-countdown');
        if (countdownEl) {
            if (minutes !== null && minutes >= 0) {
                countdownEl.textContent = `${minutes}m`;
                countdownEl.style.display = '';
            } else {
                countdownEl.style.display = 'none';
            }
        }
    }

    function renderTrainDeparturesTile(container, data) {
        if (!data.configured) {
            container.innerHTML = `
                <div class="tile-placeholder">
                    <p>Configure train departures settings to see departures</p>
                    <a href="/settings.php" class="tile-connect-btn">Configure Train Departures</a>
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

        const hasBothDirections = data.direction_ab && data.direction_ba;
        let currentDirection = data.default_direction || (new Date().getHours() < 12 ? 'ab' : 'ba');
        if (!hasBothDirections) {
            currentDirection = 'ab';
        }

        const origin = data.origin || {};
        const destination = data.destination || {};
        const departures = data.departures || [];

        if (!hasBothDirections && departures.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    <p class="empty-state-text">No departures found</p>
                    <p class="empty-state-subtext">No trains scheduled from ${escapeHtml(origin.name || origin.crs || '')} to ${escapeHtml(destination.name || destination.crs || '')}</p>
                </div>
            `;
            return;
        }

        if (hasBothDirections) {
            function oneDepRow(dep) {
                const statusClass = dep.isCancelled ? 'train-status-cancelled' : dep.isDelayed ? 'train-status-delayed' : 'train-status-ontime';
                const statusIcon = dep.isCancelled ? '✕' : dep.isDelayed ? '⚠' : '✓';
                const platformPart = dep.platform ? `Plat ${escapeHtml(dep.platform)}` : '';
                const isActualTime = dep.expectedDisplay && /^\d{1,2}:\d{2}$/.test(dep.expectedDisplay);
                const expectedPart = isActualTime && dep.expectedDisplay !== dep.scheduledDisplay && !dep.isCancelled ? ` (${escapeHtml(dep.expectedDisplay)})` : '';
                const destinationName = dep.destination && dep.destination.length > 0 ? (dep.destination[0].locationName || dep.destination[0].crs || '') : '';
                return `<div class="train-departure-item"><span class="train-time">${escapeHtml(dep.scheduledDisplay)}${expectedPart}</span><span class="train-status ${statusClass}">${statusIcon} ${escapeHtml(dep.status)}</span>${platformPart ? `<span class="train-platform">${platformPart}</span>` : ''}${destinationName ? `<span class="train-destination">${escapeHtml(destinationName)}</span>` : ''}</div>`;
            }
            const dir = currentDirection === 'ab' ? data.direction_ab : data.direction_ba;
            const o = dir.origin || {};
            const d = dir.destination || {};
            const deps = dir.departures || [];
            const listHtml = deps.length ? deps.map(oneDepRow).join('') : '<p class="empty-state-text">No departures in this direction</p>';
            const nextMins = getNextDepartureMinutes(deps);
            const countdownHtml = nextMins !== null && nextMins >= 0 ? `<span class="train-countdown">${nextMins}m</span>` : '';
            container.innerHTML = `
                <div class="train-tile-wrapper" data-train-direction="${currentDirection}">
                    <div class="train-route-row">
                        <div class="train-route-header">
                            <span class="train-station-code">${escapeHtml(o.crs || '')}</span>
                            <svg class="train-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            <span class="train-station-code">${escapeHtml(d.crs || '')}</span>
                            ${countdownHtml}
                        </div>
                        <button type="button" class="train-flip-btn" title="Flip direction">⇄</button>
                    </div>
                    <div class="train-departures-list">${listHtml}</div>
                </div>
            `;
            const wrapper = container.querySelector('.train-tile-wrapper');
            if (wrapper) {
                wrapper._trainTileData = data;
                // Set up countdown update timer
                const countdownInterval = setInterval(() => {
                    if (!document.body.contains(wrapper)) {
                        clearInterval(countdownInterval);
                        return;
                    }
                    updateTrainCountdown(wrapper);
                }, 60000); // Update every minute
                wrapper._countdownInterval = countdownInterval;
            }
            const flipBtn = container.querySelector('.train-flip-btn');
            if (flipBtn) {
                flipBtn.addEventListener('click', function () {
                    const w = this.closest('.train-tile-wrapper');
                    if (!w || !w._trainTileData) return;
                    const dataObj = w._trainTileData;
                    const current = w.getAttribute('data-train-direction') === 'ab' ? 'ba' : 'ab';
                    w.setAttribute('data-train-direction', current);
                    const nextDirData = current === 'ab' ? dataObj.direction_ab : dataObj.direction_ba;
                    if (!nextDirData) return;
                    const no = nextDirData.origin || {};
                    const nd = nextDirData.destination || {};
                    const ndeps = nextDirData.departures || [];
                    const nextMins = getNextDepartureMinutes(ndeps);
                    const countdownHtml = nextMins !== null && nextMins >= 0 ? `<span class="train-countdown">${nextMins}m</span>` : '';
                    const header = w.querySelector('.train-route-header');
                    if (header) {
                        header.innerHTML = `<span class="train-station-code">${escapeHtml(no.crs || '')}</span><svg class="train-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg><span class="train-station-code">${escapeHtml(nd.crs || '')}</span>${countdownHtml}`;
                    }
                    const listEl = w.querySelector('.train-departures-list');
                    if (listEl) {
                        listEl.innerHTML = ndeps.length ? ndeps.map(oneDepRow).join('') : '<p class="empty-state-text">No departures in this direction</p>';
                    }
                    updateTrainCountdown(w);
                });
            }
            return;
        }

        // Single-direction (legacy) response
        const routeHeader = `
            <div class="train-route-header">
                <span class="train-station-code">${escapeHtml(origin.crs || '')}</span>
                <svg class="train-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
                <span class="train-station-code">${escapeHtml(destination.crs || '')}</span>
            </div>
        `;
        const departuresHtml = departures.map(dep => {
            const statusClass = dep.isCancelled ? 'train-status-cancelled' : dep.isDelayed ? 'train-status-delayed' : 'train-status-ontime';
            const statusIcon = dep.isCancelled ? '✕' : dep.isDelayed ? '⚠' : '✓';
            const platformPart = dep.platform ? `Plat ${escapeHtml(dep.platform)}` : '';
            const isActualTime = dep.expectedDisplay && /^\d{1,2}:\d{2}$/.test(dep.expectedDisplay);
            const expectedPart = isActualTime && dep.expectedDisplay !== dep.scheduledDisplay && !dep.isCancelled ? ` (${escapeHtml(dep.expectedDisplay)})` : '';
            const destinationName = dep.destination && dep.destination.length > 0 ? (dep.destination[0].locationName || dep.destination[0].crs || '') : '';
            return `<div class="train-departure-item"><span class="train-time">${escapeHtml(dep.scheduledDisplay)}${expectedPart}</span><span class="train-status ${statusClass}">${statusIcon} ${escapeHtml(dep.status)}</span>${platformPart ? `<span class="train-platform">${platformPart}</span>` : ''}${destinationName ? `<span class="train-destination">${escapeHtml(destinationName)}</span>` : ''}</div>`;
        }).join('');
        container.innerHTML = `${routeHeader}<div class="train-departures-list">${departuresHtml}</div>`;
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

        container.innerHTML = "<div class=\"notes-container\"><textarea id=\"notes-textarea-" + tileId + "\" class=\"notes-textarea\" placeholder=\"Jot down your notes here... They will be saved automatically.\" rows=\"8\">" + escapeHtml(notes) + "</textarea><div class=\"notes-footer\"><div class=\"notes-status\"><span class=\"notes-saved-indicator\" id=\"notes-saved-" + tileId + "\"></span></div><div class=\"notes-footer-actions\"><button type=\"button\" id=\"notes-new-note-btn-" + tileId + "\" class=\"notes-footer-icon-btn\" title=\"Start a new note\" aria-label=\"Start a new note\"><svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M12 4v16m8-8H4\"/></svg></button><button type=\"button\" id=\"notes-open-popup-btn-" + tileId + "\" class=\"notes-footer-icon-btn\" title=\"Open in larger window\" aria-label=\"Open in larger window\"><svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4\"/></svg></button><button type=\"button\" id=\"notes-save-btn-" + tileId + "\" class=\"notes-footer-icon-btn notes-save-btn\" title=\"" + saveTitle + "\" aria-label=\"" + saveTitle + "\" data-current-note-id=\"" + noteIdAttr + "\"><svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 13l4 4L19 7\"/></svg></button></div></div></div>";

        // Setup auto-save
        setupNotesAutoSave(tileId);
        
        // Setup save to list button
        setupNotesSaveButton(tileId);
        
        // Setup popup button
        setupNotesPopupButton(tileId);
        
        // Setup new note button
        setupNotesNewNoteButton(tileId);
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
            var deleteSvg = "<svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16\"/></svg>";
            return "<div class=\"notes-list-item\" data-note-id=\"" + note.id + "\"><div class=\"notes-list-item-content\"><div class=\"notes-list-preview\">" + escapeHtml(note.preview) + "</div><div class=\"notes-list-date\">" + escapeHtml(dateStr) + " at " + escapeHtml(timeStr) + "</div></div><button type=\"button\" class=\"notes-list-delete\" data-note-id=\"" + note.id + "\" title=\"Delete note\" aria-label=\"Delete note\">" + deleteSvg + "</button></div>";
        }).join("");

        container.innerHTML = "<div class=\"notes-list-container\"><div class=\"notes-list\">" + notesHtml + "</div></div>";

        setupNotesListClickHandlers();
        setupNotesListDeleteHandlers(container);
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

        var bookmarksHtml;
        if (bookmarks.length === 0) {
            bookmarksHtml = "<div class=\"bookmarks-empty\"><p class=\"text-gray-500 text-sm\">No bookmarks yet.</p><p class=\"text-gray-400 text-xs mt-1\">Add a URL below to get started.</p></div>";
        } else {
            var items = bookmarks.filter(function(b) { return b && (b.url || b.id); }).map(function(b) {
                var url = b.url || "";
                var domain = getBookmarkDomain(url);
                var displayText = (b.title && b.title.trim()) ? b.title.trim() : (domain || url);
                if (displayText.length > 60) displayText = displayText.substring(0, 57) + "...";
                var titleAttr = url;
                return "<div class=\"bookmark-item\" data-bookmark-id=\"" + b.id + "\"><a class=\"bookmark-link\" href=\"" + escapeHtml(url) + "\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"" + escapeHtml(titleAttr) + "\">" + escapeHtml(displayText) + "</a><button type=\"button\" class=\"bookmark-delete\" data-bookmark-id=\"" + b.id + "\" title=\"Remove bookmark\" aria-label=\"Remove bookmark\"><svg class=\"w-3.5 h-3.5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M6 18L18 6M6 6l12 12\"/></svg></button></div>";
            });
            bookmarksHtml = "<div class=\"bookmarks-grid\">" + items.join("") + "</div>";
        }

        container.innerHTML = "<div class=\"bookmarks-tile\"><form class=\"bookmarks-add-form\" id=\"bookmarks-add-form-" + tileId + "\"><input type=\"url\" class=\"bookmarks-url-input\" placeholder=\"https://...\" required><input type=\"text\" class=\"bookmarks-title-input\" placeholder=\"Site name (optional)\" maxlength=\"255\"><button type=\"submit\" class=\"bookmarks-add-btn\">Add</button></form><div class=\"bookmarks-list\">" + bookmarksHtml + "</div></div>";

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
            const urlInput = form.querySelector(".bookmarks-url-input");
            const titleInput = form.querySelector(".bookmarks-title-input");
            const url = (urlInput && urlInput.value) ? urlInput.value.trim() : "";
            if (!url) return;
            const title = (titleInput && titleInput.value) ? titleInput.value.trim() : "";

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
                    body: JSON.stringify({ action: "add", url: url, title: title || undefined })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || "Failed to add");
                if (urlInput) urlInput.value = "";
                if (titleInput) titleInput.value = "";
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
     * Link Board tile: kanban of URL cards by category with AI summary support
     */
    function renderLinkBoardTile(container, data, tileElement) {
        const categories = data.categories || [];
        const items = data.items || [];
        const tileId = tileElement ? (parseInt(tileElement.dataset.tileId, 10) || 0) : 0;
        const uid = "lb-" + tileId + "-" + (Date.now().toString(36));

        let html = '<div class="link-board-tile" data-link-board-uid="' + uid + '">';
        html += '<div class="link-board-toolbar"><button type="button" class="link-board-add-cat-btn">+ Add category</button></div>';
        html += '<div class="link-board-columns">';
        if (categories.length === 0) {
            html += '<div class="link-board-empty-state"><p class="text-sm opacity-90">No categories yet.</p><p class="text-xs mt-1 opacity-75">Add a category to start organizing your links.</p></div>';
        } else {
            categories.forEach(function (cat) {
                const catItems = items.filter(function (it) { return it.category_id === cat.id; }).sort(function (a, b) { return (a.position || 0) - (b.position || 0); });
                html += '<div class="link-board-column" data-category-id="' + cat.id + '" data-category-name="' + escapeHtml(cat.name) + '">';
                html += '<div class="link-board-column-header">';
                html += '<span class="link-board-column-title">' + escapeHtml(cat.name) + '</span>';
                html += '<div class="link-board-column-actions">';
                html += '<button type="button" class="link-board-edit-cat" data-category-id="' + cat.id + '" data-category-name="' + escapeHtml(cat.name) + '" title="Edit category">✎</button>';
                html += '<button type="button" class="link-board-delete-cat" data-category-id="' + cat.id + '" title="Delete category">×</button>';
                html += '</div></div>';
                html += '<div class="link-board-column-body" data-category-id="' + cat.id + '">';
                html += '<button type="button" class="link-board-add-item-btn" data-category-id="' + cat.id + '">+ Add link</button>';
                catItems.forEach(function (it) {
                    const title = (it.title && it.title.trim()) ? it.title.trim() : (function(u){ try { return new URL(u).hostname || u; } catch(_){ return u; } }(it.url || ""));
                    const summary = (it.summary && it.summary.trim()) ? it.summary.trim() : "";
                    html += '<div class="link-board-card" draggable="true" data-item-id="' + it.id + '" data-category-id="' + it.category_id + '">';
                    html += '<a class="link-board-card-link" href="' + escapeHtml(it.url) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml(it.url) + '">';
                    html += '<span class="link-board-card-title">' + escapeHtml(title.length > 60 ? title.substring(0, 57) + "…" : title) + '</span>';
                    html += '</a>';
                    if (summary) {
                        html += '<div class="link-board-card-summary-wrapper">';
                        html += '<span class="link-board-card-summary" data-full-summary="' + escapeHtml(summary) + '" title="Click to view full summary">' + escapeHtml(summary.length > 120 ? summary.substring(0, 117) + "…" : summary) + '</span>';
                        html += '</div>';
                    }
                    html += '<div class="link-board-card-actions">';
                    html += '<button type="button" class="link-board-card-summarize" data-item-id="' + it.id + '" title="Generate AI summary">AI</button>';
                    html += '<button type="button" class="link-board-card-edit" data-item-id="' + it.id + '" data-url="' + escapeHtml(it.url) + '" data-title="' + escapeHtml(it.title || "") + '" data-category-id="' + it.category_id + '" title="Edit">✎</button>';
                    html += '<button type="button" class="link-board-card-delete" data-item-id="' + it.id + '" title="Remove">×</button>';
                    html += '</div></div>';
                });
                html += '</div></div>';
            });
        }
        html += '</div></div>';
        container.innerHTML = html;

        setupLinkBoardAddCategory(container, tileElement);
        setupLinkBoardEditDeleteCategory(container, tileElement);
        setupLinkBoardAddItem(container, tileElement);
        setupLinkBoardEditDeleteItem(container, tileElement);
        setupLinkBoardSummarize(container, tileElement);
        setupLinkBoardSummaryClick(container);
        setupLinkBoardDragDrop(container, tileElement);
    }

    function setupLinkBoardAddCategory(container, tileElement) {
        const btn = container.querySelector(".link-board-add-cat-btn");
        if (!btn) return;
        btn.addEventListener("click", function () {
            const name = prompt("Category name:");
            if (name === null || !name.trim()) return;
            fetch(CONFIG.linkBoardEndpoint, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                body: JSON.stringify({ action: "add_category", name: name.trim() })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.success && tileElement) loadTileData(tileElement, false);
                else alert(data.error || "Failed to add category");
            }).catch(function () { alert("Failed to add category"); });
        });
    }

    function setupLinkBoardEditDeleteCategory(container, tileElement) {
        container.querySelectorAll(".link-board-edit-cat").forEach(function (btn) {
            btn.addEventListener("click", function () {
                const id = parseInt(this.dataset.categoryId, 10);
                const current = (this.dataset.categoryName || "").replace(/&amp;/g, "&");
                const name = prompt("Category name:", current);
                if (name === null || !name.trim()) return;
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "update_category", id: id, name: name.trim() })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Failed to update");
                }).catch(function () { alert("Failed to update"); });
            });
        });
        container.querySelectorAll(".link-board-delete-cat").forEach(function (btn) {
            btn.addEventListener("click", function () {
                const id = parseInt(this.dataset.categoryId, 10);
                if (!confirm("Delete this category? Links in it will move to the first other category.")) return;
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "delete_category", id: id })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Failed to delete");
                }).catch(function () { alert("Failed to delete"); });
            });
        });
    }

    function setupLinkBoardAddItem(container, tileElement) {
        container.querySelectorAll(".link-board-add-item-btn").forEach(function (btn) {
            btn.addEventListener("click", function () {
                const categoryId = parseInt(this.dataset.categoryId, 10);
                const url = prompt("URL:");
                if (url === null || !url.trim()) return;
                let title = prompt("Title (optional):", "");
                if (title === null) title = "";
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "add_item", category_id: categoryId, url: url.trim(), title: title.trim() })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Failed to add link");
                }).catch(function () { alert("Failed to add link"); });
            });
        });
    }

    function setupLinkBoardEditDeleteItem(container, tileElement) {
        container.querySelectorAll(".link-board-card-edit").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(this.dataset.itemId, 10);
                const url = prompt("URL:", (this.dataset.url || "").replace(/&amp;/g, "&"));
                if (url === null) return;
                const title = prompt("Title (optional):", (this.dataset.title || "").replace(/&amp;/g, "&"));
                if (title === null) return;
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "update_item", id: id, url: url.trim(), title: title.trim() })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Failed to update");
                }).catch(function () { alert("Failed to update"); });
            });
        });
        container.querySelectorAll(".link-board-card-delete").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(this.dataset.itemId, 10);
                if (!confirm("Remove this link?")) return;
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "delete_item", id: id })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Failed to remove");
                }).catch(function () { alert("Failed to remove"); });
            });
        });
    }

    function setupLinkBoardSummarize(container, tileElement) {
        container.querySelectorAll(".link-board-card-summarize").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(this.dataset.itemId, 10);
                const el = this;
                el.disabled = true;
                el.textContent = "…";
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "summarize", id: id })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                    else alert(data.error || "Could not generate summary");
                }).catch(function () { alert("Could not generate summary"); }).finally(function () {
                    el.disabled = false;
                    el.textContent = "AI";
                });
            });
        });
    }

    function setupLinkBoardSummaryClick(container) {
        container.querySelectorAll(".link-board-card-summary").forEach(function (summaryEl) {
            summaryEl.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const fullSummary = this.dataset.fullSummary || "";
                if (!fullSummary) return;
                showLinkBoardSummaryModal(fullSummary);
            });
        });
    }

    function showLinkBoardSummaryModal(summaryText) {
        // Remove existing modal if any
        const existing = document.querySelector(".link-board-summary-overlay");
        if (existing) {
            existing.remove();
        }

        // Create overlay
        const overlay = document.createElement("div");
        overlay.className = "link-board-summary-overlay";
        overlay.innerHTML = '<div class="link-board-summary-modal"><div class="link-board-summary-modal-header"><h3 class="link-board-summary-modal-title">AI Summary</h3><button type="button" class="link-board-summary-modal-close" aria-label="Close">×</button></div><div class="link-board-summary-modal-body"><p class="link-board-summary-modal-text">' + escapeHtml(summaryText) + '</p></div></div>';
        document.body.appendChild(overlay);

        // Show with animation
        setTimeout(function () {
            overlay.classList.add("show");
        }, 10);

        // Close handlers
        const closeBtn = overlay.querySelector(".link-board-summary-modal-close");
        const closeModal = function () {
            overlay.classList.remove("show");
            setTimeout(function () {
                overlay.remove();
            }, 200);
        };
        closeBtn.addEventListener("click", closeModal);
        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) {
                closeModal();
            }
        });
        document.addEventListener("keydown", function escHandler(e) {
            if (e.key === "Escape" && overlay.classList.contains("show")) {
                closeModal();
                document.removeEventListener("keydown", escHandler);
            }
        });
    }

    function setupLinkBoardDragDrop(container, tileElement) {
        const columns = container.querySelectorAll(".link-board-column-body");
        let draggedCard = null;
        let dragCategoryId = null;

        container.querySelectorAll(".link-board-card").forEach(function (card) {
            card.addEventListener("dragstart", function (e) {
                draggedCard = card;
                dragCategoryId = parseInt(card.dataset.categoryId, 10);
                e.dataTransfer.setData("text/plain", JSON.stringify({ itemId: parseInt(card.dataset.itemId, 10), categoryId: dragCategoryId }));
                e.dataTransfer.effectAllowed = "move";
                card.classList.add("link-board-dragging");
            });
            card.addEventListener("dragend", function () {
                card.classList.remove("link-board-dragging");
                draggedCard = null;
            });
        });

        columns.forEach(function (col) {
            const categoryId = parseInt(col.dataset.categoryId, 10);
            col.addEventListener("dragover", function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = "move";
                if (draggedCard && parseInt(draggedCard.dataset.categoryId, 10) !== categoryId) {
                    col.classList.add("link-board-drop-target");
                }
            });
            col.addEventListener("dragleave", function () {
                col.classList.remove("link-board-drop-target");
            });
            col.addEventListener("drop", function (e) {
                e.preventDefault();
                col.classList.remove("link-board-drop-target");
                const raw = e.dataTransfer.getData("text/plain");
                if (!raw || !tileElement) return;
                let payload;
                try { payload = JSON.parse(raw); } catch (_) { return; }
                const itemId = payload.itemId;
                const fromCategoryId = payload.categoryId;
                if (!itemId || fromCategoryId === categoryId) return;
                const cardsInCol = col.querySelectorAll(".link-board-card");
                const position = cardsInCol.length;
                fetch(CONFIG.linkBoardEndpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CONFIG.csrfToken, "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({ action: "move_item", id: itemId, category_id: categoryId, position: position })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success && tileElement) loadTileData(tileElement, false);
                }).catch(function () { /* ignore */ });
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

        // Update tooltip when current_note_id changes (e.g., after loading a note)
        const updateButtonTitle = () => {
            const currentNoteId = saveBtn.dataset.currentNoteId;
            const title = currentNoteId ? 'Update existing note' : 'Save note to list and clear';
            saveBtn.title = title;
            saveBtn.setAttribute('aria-label', title);
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
            saveBtn.title = isUpdating ? 'Updating...' : 'Saving...';
            saveBtn.setAttribute('aria-label', saveBtn.title);

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
                    
                    // Show success feedback (brief delay then restore icon state)
                    saveBtn.title = data.updated ? 'Updated!' : 'Saved!';
                    saveBtn.setAttribute('aria-label', saveBtn.title);
                    setTimeout(() => {
                        updateButtonTitle();
                        saveBtn.disabled = false;
                    }, 1500);
                } else {
                    throw new Error(data.error || 'Failed to save');
                }
            } catch (error) {
                console.error('Error saving note to list:', error);
                alert('Failed to save note. Please try again.');
                updateButtonTitle();
                saveBtn.disabled = false;
            }
        });
    }

    /**
     * Setup click handlers for notes list items
     */
    function setupNotesListClickHandlers() {
        var items = document.querySelectorAll(".notes-list-item");

        items.forEach(function(item) {
            item.addEventListener("click", async function(e) {
                if (e.target.closest(".notes-list-delete")) return;
                var noteId = parseInt(this.dataset.noteId, 10);
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
     * Setup delete button handlers for notes list items
     */
    function setupNotesListDeleteHandlers(container) {
        if (!container) return;
        var deleteButtons = container.querySelectorAll(".notes-list-delete");
        var notesListTile = container.closest(".tile");

        deleteButtons.forEach(function(btn) {
            btn.addEventListener("click", async function(e) {
                e.preventDefault();
                e.stopPropagation();
                var noteId = parseInt(this.dataset.noteId, 10);
                if (!noteId) return;
                if (!confirm("Delete this note? This cannot be undone.")) return;

                try {
                    var response = await fetch(CONFIG.notesEndpoint, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": CONFIG.csrfToken,
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: JSON.stringify({ action: "delete_note", note_id: noteId })
                    });
                    var data = await response.json();
                    if (!data.success) throw new Error(data.error || "Failed to delete note");
                    if (notesListTile) loadTileData(notesListTile, false);
                    var notesTile = document.querySelector(".tile[data-tile-type=\"notes\"]");
                    if (notesTile) loadTileData(notesTile, false);
                } catch (err) {
                    console.error("Error deleting note:", err);
                    alert("Failed to delete note. Please try again.");
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
     * Setup new note button for notes tile
     * If the edit box has content, saves it to a new note first, then clears the tile.
     */
    function setupNotesNewNoteButton(tileId) {
        const newNoteBtn = document.getElementById(`notes-new-note-btn-${tileId}`);
        const textarea = document.getElementById(`notes-textarea-${tileId}`);
        const saveBtn = document.getElementById(`notes-save-btn-${tileId}`);
        if (!newNoteBtn || !textarea || !saveBtn) return;

        const clearTileForNewNote = function() {
            textarea.value = '';
            saveBtn.dataset.currentNoteId = '';
            saveBtn.title = 'Save note to list and clear';
            saveBtn.setAttribute('aria-label', saveBtn.title);
            textarea.dispatchEvent(new Event('input'));
        };

        newNoteBtn.addEventListener('click', async function() {
            const notes = textarea.value.trim();
            try {
                if (notes) {
                    var saveRes = await fetch(CONFIG.notesEndpoint, {
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
                    var saveData = await saveRes.json();
                    if (!saveData.success) throw new Error(saveData.error || 'Failed to save note');
                    refreshNotesListTile();
                }
                var newRes = await fetch(CONFIG.notesEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CONFIG.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'new_note', tile_id: parseInt(tileId) })
                });
                var newData = await newRes.json();
                if (!newData.success) throw new Error(newData.error || 'Failed to start new note');
                clearTileForNewNote();
            } catch (err) {
                console.error('New note error:', err);
                alert(err.message || 'Failed to start new note. Please try again.');
            }
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
            var title = tileSaveBtn.dataset.currentNoteId ? 'Update existing note' : 'Save note to list and clear';
            tileSaveBtn.title = title;
            tileSaveBtn.setAttribute('aria-label', title);
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
    }

    /**
     * Setup auto-refresh for individual tiles
     */
    function setupAutoRefresh() {
        // Clear any existing timers
        clearAllTileRefreshTimers();
        
        // Set up refresh timers for each tile based on their individual refresh intervals
        const tiles = document.querySelectorAll('.tile[data-tile-type][data-refresh-interval]');
        tiles.forEach(tile => {
            setupTileRefreshTimer(tile);
        });

        // Update status display
        const statusEl = document.getElementById('autoRefreshStatus');
        if (statusEl) {
            const hasTimers = tileRefreshTimers.size > 0;
            statusEl.textContent = hasTimers ? 'enabled' : 'disabled';
            statusEl.className = hasTimers ? 'font-medium text-green-600' : 'font-medium text-gray-500';
        }
    }

    /**
     * Setup refresh timer for a single tile
     */
    function setupTileRefreshTimer(tile) {
        // Clear existing timer for this tile if any
        clearTileRefreshTimer(tile);
        
        const refreshInterval = parseInt(tile.dataset.refreshInterval) || 0;
        const tileType = tile.dataset.tileType;
        
        // Skip tiles that shouldn't auto-refresh (claude, notes, bookmarks)
        if (tileType === 'claude' || tileType === 'notes' || tileType === 'notes-list' || tileType === 'bookmarks' || tileType === 'link-board') {
            return;
        }
        
        // Only set up timer if refresh interval is positive
        if (refreshInterval > 0) {
            const timerId = setInterval(() => {
                loadTileData(tile, false);
                lastUpdateTime = new Date();
                updateLastUpdateDisplay();
            }, refreshInterval);
            
            tileRefreshTimers.set(tile, timerId);
        }
    }

    /**
     * Clear refresh timer for a specific tile
     */
    function clearTileRefreshTimer(tile) {
        const timerId = tileRefreshTimers.get(tile);
        if (timerId) {
            clearInterval(timerId);
            tileRefreshTimers.delete(tile);
        }
    }

    /**
     * Clear all tile refresh timers
     */
    function clearAllTileRefreshTimers() {
        tileRefreshTimers.forEach((timerId) => {
            clearInterval(timerId);
        });
        tileRefreshTimers.clear();
        
        // Also clear the old global timer if it exists
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    }

    /**
     * Update last update time display
     */
    function updateLastUpdateDisplay() {
        const els = document.querySelectorAll('.last-update-display');
        if (!els.length) return;

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

        els.forEach(el => { el.textContent = text; });
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

        const content = tile.querySelector('.tile-content-inner') || tile.querySelector('.tile-content');
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
     * Return inline style for task item background when overdue (uses --cb-primary with opacity by days overdue).
     * @param {string|null} dueDateTimeIso - ISO date string or null
     * @param {boolean} completed - if true, no highlight
     * @returns {string} style attribute value or ''
     */
    function getOverdueBackgroundStyle(dueDateTimeIso, completed) {
        if (completed || !dueDateTimeIso) return '';
        const due = new Date(dueDateTimeIso);
        const now = new Date();
        if (due >= now) return '';
        const daysOverdue = Math.floor((now - due) / 86400000);
        if (daysOverdue <= 0) return '';
        let pct;
        if (daysOverdue <= 30) pct = 5 + (daysOverdue / 30) * 15;
        else pct = 20 + ((daysOverdue - 30) / 335) * 30;
        pct = Math.min(50, Math.round(pct));
        return `background: color-mix(in srgb, var(--cb-primary) ${pct}%, transparent);`;
    }

    /**
     * Mobile header rollover effect - reveal header on hover
     */
    function setupHeaderRollover() {
        const header = document.getElementById('mainHeader');
        const hoverZone = document.getElementById('headerHoverZone');
        if (!header || !hoverZone) return;

        // Enable only on medium/large screens (769px–1920px). On mobile (≤768px) header is always visible with hamburger.
        function isRolloverScreen() {
            return window.matchMedia('(min-width: 769px) and (max-width: 1920px)').matches;
        }

        if (!isRolloverScreen()) return;

        let hideTimeout = null;

        function revealHeader() {
            if (hideTimeout) {
                clearTimeout(hideTimeout);
                hideTimeout = null;
            }
            header.classList.add('header-revealed');
        }

        function hideHeader() {
            // Delay hiding to allow moving cursor to header
            hideTimeout = setTimeout(() => {
                // Only hide if not hovering over header
                if (!header.matches(':hover')) {
                    header.classList.remove('header-revealed');
                }
            }, 200);
        }

        // Reveal when hovering over hover zone
        hoverZone.addEventListener('mouseenter', revealHeader);
        hoverZone.addEventListener('mouseleave', hideHeader);

        // Keep revealed when hovering over header
        header.addEventListener('mouseenter', () => {
            if (hideTimeout) {
                clearTimeout(hideTimeout);
                hideTimeout = null;
            }
            header.classList.add('header-revealed');
        });

        // Hide when leaving header (unless hovering over hover zone)
        header.addEventListener('mouseleave', () => {
            if (!hoverZone.matches(':hover')) {
                hideHeader();
            }
        });
    }

    /**
     * Mobile header menu toggle (hamburger)
     */
    function setupHeaderMenuButton() {
        const btn = document.getElementById('headerMenuButton');
        const panel = document.getElementById('headerMenuPanel');
        const iconOpen = document.getElementById('headerMenuIconOpen');
        const iconClose = document.getElementById('headerMenuIconClose');
        if (!btn || !panel) return;

        function openMenu() {
            panel.classList.remove('hidden');
            panel.setAttribute('aria-hidden', 'false');
            btn.setAttribute('aria-expanded', 'true');
            btn.setAttribute('aria-label', 'Close menu');
            if (iconOpen) iconOpen.classList.add('hidden');
            if (iconClose) iconClose.classList.remove('hidden');
        }
        function closeMenu() {
            panel.classList.add('hidden');
            panel.setAttribute('aria-hidden', 'true');
            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-label', 'Open menu');
            if (iconOpen) iconOpen.classList.remove('hidden');
            if (iconClose) iconClose.classList.add('hidden');
        }

        btn.addEventListener('click', () => {
            const isOpen = panel.classList.contains('hidden');
            if (isOpen) openMenu(); else closeMenu();
        });

        // Close when clicking a link (navigation) or outside
        panel.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', () => { closeMenu(); });
        });
        document.addEventListener('click', (e) => {
            if (!panel.classList.contains('hidden') && !panel.contains(e.target) && !btn.contains(e.target)) {
                closeMenu();
            }
        });
    }

    /**
     * Setup reorder mode toggle and drag-and-drop
     */
    function setupReorderMode() {
        const reorderBtn = document.getElementById('reorderTiles');
        const reorderBtnMobile = document.getElementById('reorderTilesMobile');
        const saveBtn = document.getElementById('saveOrder');
        const saveBtnMobile = document.getElementById('saveOrderMobile');
        const cancelBtn = document.getElementById('cancelReorder');
        const cancelBtnMobile = document.getElementById('cancelReorderMobile');
        const container = getActiveTilesContainer();

        if (!container) return;
        const triggerReorder = () => enterReorderMode();
        const triggerSave = () => saveNewOrder();
        const triggerCancel = () => exitReorderMode(false);

        if (reorderBtn) reorderBtn.addEventListener('click', triggerReorder);
        if (reorderBtnMobile) reorderBtnMobile.addEventListener('click', triggerReorder);
        if (saveBtn) saveBtn.addEventListener('click', triggerSave);
        if (saveBtnMobile) saveBtnMobile.addEventListener('click', triggerSave);
        if (cancelBtn) cancelBtn.addEventListener('click', triggerCancel);
        if (cancelBtnMobile) cancelBtnMobile.addEventListener('click', triggerCancel);
    }

    /**
     * Dashboard tabs (Main / Screen 2): switch panels and persist selection.
     */
    function setupDashboardTabs() {
        const tabs = document.querySelectorAll('.dashboard-tab');
        const panels = document.querySelectorAll('.dashboard-panel');
        const STORAGE_KEY = 'crashboard_active_tab';

        function showPanel(tabId) {
            tabs.forEach(tab => {
                const isActive = (tab.dataset.tab || tab.getAttribute('id')) === tabId;
                tab.classList.toggle('opacity-70', !isActive);
                tab.classList.remove('dashboard-tab-active');
                if (isActive) tab.classList.add('dashboard-tab-active');
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            panels.forEach(panel => {
                const panelScreen = panel.dataset.screen;
                const isActive = panelScreen === tabId;
                panel.classList.toggle('hidden', !isActive);
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
            try { sessionStorage.setItem(STORAGE_KEY, tabId); } catch (e) {}
            // If we were in reorder mode, exit it when switching tabs
            if (typeof isReorderMode !== 'undefined' && isReorderMode) {
                exitReorderMode(false);
            }
        }

        const initial = (function() {
            try { return sessionStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
        })();
        const firstTab = tabs[0] && (tabs[0].dataset.tab || tabs[0].getAttribute('id'));
        if (initial === 'main' || initial === 'screen2') {
            showPanel(initial);
        } else if (firstTab) {
            showPanel(firstTab);
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.dataset.tab || this.getAttribute('id');
                if (tabId) showPanel(tabId);
            });
        });
    }

    /**
     * Enter reorder mode
     */
    function enterReorderMode() {
        isReorderMode = true;
        const container = getActiveTilesContainer();
        const reorderBtn = document.getElementById('reorderTiles');
        const reorderBtnMobile = document.getElementById('reorderTilesMobile');
        const reorderControls = document.getElementById('reorderControls');
        const reorderControlsMobile = document.getElementById('reorderControlsMobile');

        // Update UI (desktop + mobile)
        container.classList.add('reorder-mode');
        if (reorderBtn) reorderBtn.classList.add('hidden');
        if (reorderBtnMobile) reorderBtnMobile.classList.add('hidden');
        if (reorderControls) reorderControls.classList.remove('hidden');
        if (reorderControlsMobile) reorderControlsMobile.classList.remove('hidden');

        // Enable tile resize handles (only active in reorder mode)
        setupTileResize();

        // Current panel screen (main or screen2) for "move to other screen" target
        const currentScreen = (container.closest('.dashboard-panel') && container.closest('.dashboard-panel').dataset.screen) || 'main';
        const targetScreen = currentScreen === 'main' ? 'screen2' : 'main';
        const targetLabel = screenLabels[targetScreen] || (targetScreen === 'main' ? 'Main' : 'Screen 2');

        // Make tiles draggable and add move-to-screen button (only for saved tiles with id > 0)
        const tiles = container.querySelectorAll('.tile');
        tiles.forEach(tile => {
            tile.setAttribute('draggable', 'true');
            tile.classList.add('reorder-tile');

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

            const tileId = parseInt(tile.dataset.tileId, 10);
            if (header && tileId > 0 && !header.querySelector('.tile-move-screen')) {
                const moveBtn = document.createElement('button');
                moveBtn.type = 'button';
                moveBtn.className = 'tile-move-screen';
                moveBtn.title = 'Move to ' + targetLabel;
                moveBtn.setAttribute('aria-label', 'Move this tile to ' + targetLabel);
                moveBtn.innerHTML = `
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    <span class="tile-move-screen-label">To ${targetLabel}</span>
                `;
                moveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    moveTileToScreen(tile, targetScreen);
                });
                const refreshBtn = header.querySelector('.tile-refresh');
                if (refreshBtn) {
                    header.insertBefore(moveBtn, refreshBtn);
                } else {
                    header.appendChild(moveBtn);
                }
            }

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
        const container = getActiveTilesContainer();
        document.querySelectorAll('.tiles-grid').forEach(el => el.classList.remove('reorder-mode'));
        const reorderBtn = document.getElementById('reorderTiles');
        const reorderBtnMobile = document.getElementById('reorderTilesMobile');
        const reorderControls = document.getElementById('reorderControls');
        const reorderControlsMobile = document.getElementById('reorderControlsMobile');

        // Update UI (desktop + mobile)
        container.classList.remove('reorder-mode');
        if (reorderBtn) reorderBtn.classList.remove('hidden');
        if (reorderBtnMobile) reorderBtnMobile.classList.remove('hidden');
        if (reorderControls) reorderControls.classList.add('hidden');
        if (reorderControlsMobile) reorderControlsMobile.classList.add('hidden');

        // Close mobile menu if open
        const menuPanel = document.getElementById('headerMenuPanel');
        const menuBtn = document.getElementById('headerMenuButton');
        const iconOpen = document.getElementById('headerMenuIconOpen');
        const iconClose = document.getElementById('headerMenuIconClose');
        if (menuPanel && !menuPanel.classList.contains('hidden')) {
            menuPanel.classList.add('hidden');
            menuPanel.setAttribute('aria-hidden', 'true');
            if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.setAttribute('aria-label', 'Open menu'); }
            if (iconOpen) iconOpen.classList.remove('hidden');
            if (iconClose) iconClose.classList.add('hidden');
        }

        // Remove draggable, move-to-screen button, and event listeners from ALL tiles (both panels)
        const tiles = document.querySelectorAll('.tile');
        tiles.forEach(tile => {
            tile.removeAttribute('draggable');
            tile.classList.remove('reorder-tile', 'drag-over');

            const handle = tile.querySelector('.drag-handle');
            if (handle) handle.remove();
            const moveBtn = tile.querySelector('.tile-move-screen');
            if (moveBtn) moveBtn.remove();

            tile.removeEventListener('dragstart', handleDragStart);
            tile.removeEventListener('dragend', handleDragEnd);
            tile.removeEventListener('dragover', handleDragOver);
            tile.removeEventListener('dragenter', handleDragEnter);
            tile.removeEventListener('dragleave', handleDragLeave);
            tile.removeEventListener('drop', handleDrop);
        });

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
     * Handle drag over - allow drop and show move cursor
     */
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        return false;
    }

    /**
     * Handle drag enter - live reorder: move dragged tile in DOM so layout previews before drop
     */
    function handleDragEnter(e) {
        if (!draggedTile || this === draggedTile) return;
        e.preventDefault();

        const container = getActiveTilesContainer();
        if (!container) return;
        const tiles = Array.from(container.querySelectorAll('.tile'));
        const draggedIndex = tiles.indexOf(draggedTile);
        const targetIndex = tiles.indexOf(this);
        if (draggedIndex === -1 || targetIndex === -1 || draggedIndex === targetIndex) {
            this.classList.add('drag-over');
            return;
        }

        // Move dragged tile so it sits at the target position (push others out of the way)
        const targetTile = tiles[targetIndex];
        if (draggedIndex < targetIndex) {
            container.insertBefore(draggedTile, targetTile.nextSibling);
        } else {
            container.insertBefore(draggedTile, targetTile);
        }

        this.classList.add('drag-over');
    }

    /**
     * Handle drag leave - only remove highlight when actually leaving the tile
     */
    function handleDragLeave(e) {
        if (e.relatedTarget && !this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
        }
    }

    /**
     * Handle drop - order already updated by dragenter; just clean up
     */
    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        if (draggedTile !== this) {
            const container = getActiveTilesContainer();
            if (container) {
                const tiles = Array.from(container.querySelectorAll('.tile'));
                const draggedIndex = tiles.indexOf(draggedTile);
                const targetIndex = tiles.indexOf(this);
                if (draggedIndex !== -1 && targetIndex !== -1 && draggedIndex !== targetIndex) {
                    const targetTile = tiles[targetIndex];
                    if (draggedIndex < targetIndex) {
                        container.insertBefore(draggedTile, targetTile.nextSibling);
                    } else {
                        container.insertBefore(draggedTile, targetTile);
                    }
                }
            }
        }
        document.querySelectorAll('.tile').forEach(function(t) { t.classList.remove('drag-over'); });
        return false;
    }

    /**
     * Move a tile to another dashboard screen (main or screen2).
     */
    async function moveTileToScreen(tileEl, targetScreen) {
        const tileId = parseInt(tileEl.dataset.tileId, 10);
        if (!tileId || tileId <= 0) return;
        const moveBtn = tileEl.querySelector('.tile-move-screen');
        if (moveBtn) moveBtn.disabled = true;
        try {
            const response = await fetch(CONFIG.moveScreenEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ tile_id: tileId, screen: targetScreen })
            });
            const data = await response.json();
            if (data.success) {
                const otherPanel = document.querySelector('.dashboard-panel[data-screen="' + targetScreen + '"]');
                const otherGrid = otherPanel ? otherPanel.querySelector('.tiles-grid') : null;
                if (otherGrid) {
                    tileEl.remove();
                    otherGrid.appendChild(tileEl);
                    const emptyPlaceholder = otherGrid.querySelector('.col-span-full');
                    if (emptyPlaceholder) emptyPlaceholder.remove();
                }
                showToast('Tile moved to ' + (screenLabels[targetScreen] || targetScreen), 'success');
            } else {
                showToast(data.error || 'Failed to move tile', 'error');
            }
        } catch (err) {
            console.error('Move tile error:', err);
            showToast('Failed to move tile', 'error');
        }
        if (moveBtn) moveBtn.disabled = false;
    }

    /**
     * Save the new tile order
     */
    async function saveNewOrder() {
        const container = getActiveTilesContainer();
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
        if (!isReorderMode || isResizing) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const handle = e.currentTarget;
        const tile = handle.closest('.tile-resizable');
        if (!tile) return;
        
        isResizing = true;
        resizeHandle = handle;
        resizeTile = tile;
        
        resizeTile.classList.add('resizing');
        resizeStartX = e.clientX;
        resizeStartY = e.clientY;
        resizeStartColSpan = parseInt(resizeTile.dataset.columnSpan) || 1;
        resizeStartRowSpan = parseInt(resizeTile.dataset.rowSpan) || 1;
        
        document.body.style.cursor = resizeHandle.classList.contains('tile-resize-handle-se') ? 'nwse-resize' :
                                      resizeHandle.classList.contains('tile-resize-handle-e') ? 'ew-resize' : 'ns-resize';
        document.body.style.userSelect = 'none';
    }

    let resizeRAF = null;

    /**
     * Handle resize move (throttled with requestAnimationFrame, progressive origin)
     */
    function handleResizeMove(e) {
        if (!isResizing || !resizeTile) return;
        
        e.preventDefault();
        
        if (resizeRAF) return;
        resizeRAF = requestAnimationFrame(function() {
            resizeRAF = null;
            if (!resizeTile) return;
            
            const deltaX = e.clientX - resizeStartX;
            const deltaY = e.clientY - resizeStartY;
            
            const container = getActiveTilesContainer();
            if (!container) return;
            
            const gap = 24; // 1.5rem
            const containerWidth = container.offsetWidth;
            const cellWidth = (containerWidth - gap * (CONFIG.gridColumns - 1)) / CONFIG.gridColumns;
            
            // Use actual tile height to derive row height (grid uses minmax(200px, auto))
            const tileHeight = resizeTile.getBoundingClientRect().height;
            const rowCount = Math.max(1, parseInt(resizeTile.dataset.rowSpan) || 1);
            // With N rows there are (N-1) gaps inside the tile's span; one row height = (tileHeight - (N-1)*gap) / N
            const cellHeight = rowCount <= 1 ? tileHeight : (tileHeight - (rowCount - 1) * gap) / rowCount;
            
            let newColSpan = resizeStartColSpan;
            let newRowSpan = resizeStartRowSpan;
            
            if (resizeHandle.classList.contains('tile-resize-handle-se')) {
                const colDelta = Math.round(deltaX / cellWidth);
                const rowDelta = Math.round(deltaY / cellHeight);
                newColSpan = Math.max(1, Math.min(5, resizeStartColSpan + colDelta));
                newRowSpan = Math.max(1, Math.min(5, resizeStartRowSpan + rowDelta));
            } else if (resizeHandle.classList.contains('tile-resize-handle-e')) {
                const colDelta = Math.round(deltaX / cellWidth);
                newColSpan = Math.max(1, Math.min(5, resizeStartColSpan + colDelta));
            } else if (resizeHandle.classList.contains('tile-resize-handle-s')) {
                const rowDelta = Math.round(deltaY / cellHeight);
                newRowSpan = Math.max(1, Math.min(5, resizeStartRowSpan + rowDelta));
            }
            
            const prevCol = parseInt(resizeTile.dataset.columnSpan) || 1;
            const prevRow = parseInt(resizeTile.dataset.rowSpan) || 1;
            
            resizeTile.style.gridColumn = 'span ' + newColSpan;
            resizeTile.style.gridRow = 'span ' + newRowSpan;
            resizeTile.dataset.columnSpan = newColSpan;
            resizeTile.dataset.rowSpan = newRowSpan;
            
            // Progressive origin: when span actually changes, reset drag start so next drag is incremental
            if (newColSpan !== prevCol || newRowSpan !== prevRow) {
                resizeStartX = e.clientX;
                resizeStartY = e.clientY;
                resizeStartColSpan = newColSpan;
                resizeStartRowSpan = newRowSpan;
            }
        });
    }

    /**
     * Handle resize end
     */
    async function handleResizeEnd(e) {
        if (!isResizing || !resizeTile) return;
        
        e.preventDefault();
        if (resizeRAF) {
            cancelAnimationFrame(resizeRAF);
            resizeRAF = null;
        }
        
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
