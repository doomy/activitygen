const API_BASE = '/api';

class ActivityGenApp {
    constructor() {
        this.currentSuggestion = null;
        this.syncStatusInterval = null;
        this.isPollingActive = false;

        this.initElements();
        this.attachEventListeners();
        this.setupVisibilityChangeHandler();
        
        // Only start polling if the page is visible
        if (!document.hidden) {
            this.startSyncStatusPolling();
        }
    }

    initElements() {
        // Tabs
        this.tabs = document.querySelectorAll('.tab');
        this.views = {
            suggest: document.getElementById('suggestView'),
            manage: document.getElementById('manageView'),
        };

        // Suggestion view
        this.suggestionContent = document.getElementById('suggestionContent');
        this.btnThumbsDown = document.getElementById('btnThumbsDown');
        this.btnNext = document.getElementById('btnNext');
        this.btnThumbsUp = document.getElementById('btnThumbsUp');

        // Manage view
        this.btnShowAddForm = document.getElementById('btnShowAddForm');
        this.addFormContainer = document.getElementById('addFormContainer');
        this.newActivityName = document.getElementById('newActivityName');
        this.newActivityPriority = document.getElementById('newActivityPriority');
        this.btnAddActivity = document.getElementById('btnAddActivity');
        this.btnCancelAdd = document.getElementById('btnCancelAdd');
        this.activitiesList = document.getElementById('activitiesList');

        // Sync status
        this.connectionStatus = document.getElementById('connectionStatus');
        this.pendingCount = document.getElementById('pendingCount');
        this.syncButton = document.getElementById('syncButton');

        // Notification
        this.notification = document.getElementById('notification');
    }

    attachEventListeners() {
        // Tab switching
        this.tabs.forEach(tab => {
            tab.addEventListener('click', () => this.switchTab(tab.dataset.view));
        });

        // Suggestion actions
        this.btnThumbsDown.addEventListener('click', () => this.adjustPriority(-0.1));
        this.btnNext.addEventListener('click', () => this.getNextSuggestion());
        this.btnThumbsUp.addEventListener('click', () => this.adjustPriority(0.1));

        // Manage actions
        this.btnShowAddForm.addEventListener('click', () => this.toggleAddForm(true));
        this.btnCancelAdd.addEventListener('click', () => this.toggleAddForm(false));
        this.btnAddActivity.addEventListener('click', () => this.addActivity());
        this.newActivityName.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.addActivity();
        });

        // Sync button
        this.syncButton.addEventListener('click', () => this.manualSync());
    }

    switchTab(viewName) {
        this.tabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.view === viewName);
        });

        Object.entries(this.views).forEach(([name, view]) => {
            view.classList.toggle('active', name === viewName);
        });

        if (viewName === 'manage') {
            this.loadActivities();
        }
    }

    async getNextSuggestion() {
        this.showLoadingState();
        this.enableActionButtons(false);

        try {
            const response = await fetch(`${API_BASE}/activities/suggest`);
            const result = await response.json();

            if (result.success) {
                this.currentSuggestion = result.data;
                this.displaySuggestion(result.data);
                this.enableActionButtons(true);
            } else {
                this.showNotification(result.error, 'error');
                this.currentSuggestion = null;
                this.enableActionButtons(false);
            }
        } catch (error) {
            this.showNotification('Failed to get suggestion', 'error');
            console.error('Error getting suggestion:', error);
        }
    }

    displaySuggestion(suggestion) {
        this.suggestionContent.innerHTML = `
            <div class="activity-name">${this.escapeHtml(suggestion.activity)}</div>
            <div class="activity-details">
                <div>Priority: ${suggestion.priority.toFixed(1)}</div>
                <div>Min Roll: ${suggestion.minRoll.toFixed(1)}</div>
            </div>
        `;
    }

    showLoadingState() {
        this.suggestionContent.innerHTML = `
            <div class="spinner"></div>
            <div class="loading-text">Finding next activity...</div>
        `;
    }

    enableActionButtons(enabled) {
        this.btnThumbsDown.disabled = !enabled;
        this.btnThumbsUp.disabled = !enabled;
    }

    async adjustPriority(delta) {
        if (!this.currentSuggestion) return;

        try {
            const response = await fetch(
                `${API_BASE}/activities/${encodeURIComponent(this.currentSuggestion.activity)}/priority`,
                {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ delta }),
                },
            );

            const result = await response.json();

            if (result.success) {
                this.showNotification(
                    `Priority ${delta > 0 ? 'increased' : 'decreased'} to ${result.data.priority.toFixed(1)}`,
                    'success',
                );
                this.getNextSuggestion();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to adjust priority', 'error');
            console.error('Error adjusting priority:', error);
        }
    }

    async loadActivities() {
        try {
            this.activitiesList.innerHTML = '<p class="loading">Loading activities...</p>';

            const response = await fetch(`${API_BASE}/activities`);
            const result = await response.json();

            if (result.success) {
                this.renderActivities(result.data);
            } else {
                this.activitiesList.innerHTML = `<p class="loading">${result.error}</p>`;
            }
        } catch (error) {
            this.activitiesList.innerHTML = '<p class="loading">Failed to load activities</p>';
            console.error('Error loading activities:', error);
        }
    }

    renderActivities(activities) {
        if (activities.length === 0) {
            this.activitiesList.innerHTML = '<p class="loading">No activities yet. Add one to get started!</p>';
            return;
        }

        // Sort by priority descending
        activities.sort((a, b) => b.priority - a.priority);

        this.activitiesList.innerHTML = activities
            .map(
                activity => `
            <div class="activity-item" data-name="${this.escapeHtml(activity.activity)}">
                <div class="activity-info">
                    <div class="activity-item-name">${this.escapeHtml(activity.activity)}</div>
                    <div class="activity-item-priority">Priority: ${activity.priority.toFixed(1)}</div>
                </div>
                <div class="activity-actions">
                    <button class="btn-small btn-delete" onclick="app.deleteActivity('${this.escapeHtml(activity.activity).replace(/'/g, "\\'")}')">Delete</button>
                </div>
            </div>
        `,
            )
            .join('');
    }

    toggleAddForm(show) {
        if (show) {
            this.addFormContainer.classList.remove('hidden');
            this.newActivityName.focus();
        } else {
            this.addFormContainer.classList.add('hidden');
            this.newActivityName.value = '';
            this.newActivityPriority.value = '';
        }
    }

    async addActivity() {
        const name = this.newActivityName.value.trim();
        const priority = this.newActivityPriority.value ? parseFloat(this.newActivityPriority.value) : 1.0;

        if (!name) {
            this.showNotification('Activity name is required', 'error');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/activities`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, priority }),
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(`Activity "${name}" added`, 'success');
                this.toggleAddForm(false);
                this.loadActivities();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to add activity', 'error');
            console.error('Error adding activity:', error);
        }
    }

    async deleteActivity(name) {
        if (!confirm(`Delete activity "${name}"?`)) return;

        try {
            const response = await fetch(`${API_BASE}/activities/${encodeURIComponent(name)}`, {
                method: 'DELETE',
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(`Activity "${name}" deleted`, 'success');
                this.loadActivities();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to delete activity', 'error');
            console.error('Error deleting activity:', error);
        }
    }

    async updateSyncStatus() {
        try {
            const response = await fetch(`${API_BASE}/sync/status`);
            const result = await response.json();

            if (result.success) {
                const { online, pendingOperations } = result.data;

                this.connectionStatus.className = `status-badge ${online ? 'online' : 'offline'}`;
                this.pendingCount.textContent = pendingOperations > 0 ? `${pendingOperations} pending` : '';
                this.syncButton.disabled = !online;
            }
        } catch (error) {
            console.error('Error updating sync status:', error);
        }
    }

    async manualSync() {
        try {
            this.syncButton.disabled = true;
            this.syncButton.textContent = 'Syncing...';

            const response = await fetch(`${API_BASE}/sync`, { method: 'POST' });
            const result = await response.json();

            if (result.success) {
                this.showNotification('Sync completed', 'success');
                this.updateSyncStatus();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Sync failed', 'error');
            console.error('Error syncing:', error);
        } finally {
            this.syncButton.textContent = 'Sync';
            this.updateSyncStatus();
        }
    }

    setupVisibilityChangeHandler() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopSyncStatusPolling();
            } else {
                this.startSyncStatusPolling();
            }
        });
    }

    startSyncStatusPolling() {
        if (this.isPollingActive) {
            return;
        }
        
        this.isPollingActive = true;
        this.updateSyncStatus();
        this.syncStatusInterval = setInterval(() => this.updateSyncStatus(), 5000);
    }

    stopSyncStatusPolling() {
        if (this.syncStatusInterval) {
            clearInterval(this.syncStatusInterval);
            this.syncStatusInterval = null;
        }
        this.isPollingActive = false;
    }

    showNotification(message, type = 'success') {
        this.notification.textContent = message;
        this.notification.className = `notification ${type}`;

        setTimeout(() => {
            this.notification.className = 'notification hidden';
        }, 3000);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize app
const app = new ActivityGenApp();
