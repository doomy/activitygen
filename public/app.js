const API_BASE = '/api';
const PROJECT_ID_STORAGE_KEY = 'activitygen_project_id';
const SEARCH_DEBOUNCE_MS = 150;

class ActivityGenApp {
    constructor() {
        this.currentSuggestion = null;
        this.syncStatusInterval = null;
        this.isPollingActive = false;
        this.currentProjectId = null;
        this.projects = [];
        this.confirmDialogResolve = null;
        this.searchQuery = '';
        this.searchDebounce = null;

        this.initElements();
        this.attachEventListeners();
        this.setupVisibilityChangeHandler();

        this.init();
    }

    async init() {
        await this.loadProjects();

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

        // Project selector
        this.projectSelect = document.getElementById('projectSelect');
        this.btnShowAddProject = document.getElementById('btnShowAddProject');
        this.btnDeleteProject = document.getElementById('btnDeleteProject');
        this.addProjectFormContainer = document.getElementById('addProjectFormContainer');
        this.newProjectName = document.getElementById('newProjectName');
        this.btnAddProject = document.getElementById('btnAddProject');
        this.btnCancelAddProject = document.getElementById('btnCancelAddProject');

        // Suggestion view
        this.suggestionContent = document.getElementById('suggestionContent');
        this.btnThumbsDown = document.getElementById('btnThumbsDown');
        this.btnNext = document.getElementById('btnNext');
        this.btnThumbsUp = document.getElementById('btnThumbsUp');
        this.btnDeleteSuggestion = document.getElementById('btnDeleteSuggestion');

        // Confirm dialog
        this.confirmDialogOverlay = document.getElementById('confirmDialogOverlay');
        this.confirmDialogMessage = document.getElementById('confirmDialogMessage');
        this.confirmDialogCancel = document.getElementById('confirmDialogCancel');
        this.confirmDialogConfirm = document.getElementById('confirmDialogConfirm');

        // Manage view
        this.btnShowAddForm = document.getElementById('btnShowAddForm');
        this.addFormContainer = document.getElementById('addFormContainer');
        this.newActivityName = document.getElementById('newActivityName');
        this.newActivityPriority = document.getElementById('newActivityPriority');
        this.btnAddActivity = document.getElementById('btnAddActivity');
        this.btnCancelAdd = document.getElementById('btnCancelAdd');
        this.activitySearch = document.getElementById('activitySearch');
        this.btnClearSearch = document.getElementById('btnClearSearch');
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

        // Project selector
        this.projectSelect.addEventListener('change', () => this.switchProject(this.projectSelect.value));
        this.btnShowAddProject.addEventListener('click', () => this.toggleAddProjectForm(true));
        this.btnDeleteProject.addEventListener('click', () => this.deleteProject());
        this.btnCancelAddProject.addEventListener('click', () => this.toggleAddProjectForm(false));
        this.btnAddProject.addEventListener('click', () => this.addProject());
        this.newProjectName.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.addProject();
        });

        // Suggestion actions
        this.btnThumbsDown.addEventListener('click', () => this.adjustPriority(-0.1));
        this.btnNext.addEventListener('click', () => this.getNextSuggestion());
        this.btnThumbsUp.addEventListener('click', () => this.adjustPriority(0.1));
        this.btnDeleteSuggestion.addEventListener('click', () => this.deleteCurrentSuggestion());

        // Confirm dialog
        this.confirmDialogCancel.addEventListener('click', () => this.resolveConfirmDialog(false));
        this.confirmDialogConfirm.addEventListener('click', () => this.resolveConfirmDialog(true));
        this.confirmDialogOverlay.addEventListener('click', (e) => {
            if (e.target === this.confirmDialogOverlay) this.resolveConfirmDialog(false);
        });

        // Manage actions
        this.btnShowAddForm.addEventListener('click', () => this.toggleAddForm(true));
        this.btnCancelAdd.addEventListener('click', () => this.toggleAddForm(false));
        this.btnAddActivity.addEventListener('click', () => this.addActivity());
        this.newActivityName.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.addActivity();
        });

        // Search
        this.activitySearch.addEventListener('input', () => this.onSearchInput());
        this.activitySearch.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.clearSearch();
        });
        this.btnClearSearch.addEventListener('click', () => this.clearSearch());

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

    async loadProjects() {
        try {
            const response = await fetch(`${API_BASE}/projects`);
            const result = await response.json();

            if (!result.success) {
                this.showNotification(result.error, 'error');
                return;
            }

            this.projects = result.data;
            this.renderProjectSelect();

            const storedProjectId = localStorage.getItem(PROJECT_ID_STORAGE_KEY);
            const storedProjectExists = this.projects.some(p => String(p.id) === storedProjectId);

            this.currentProjectId = storedProjectExists
                ? storedProjectId
                : (this.projects[0] ? String(this.projects[0].id) : null);

            if (this.currentProjectId) {
                this.projectSelect.value = this.currentProjectId;
                localStorage.setItem(PROJECT_ID_STORAGE_KEY, this.currentProjectId);
            }

            this.onProjectChanged();
        } catch (error) {
            this.showNotification('Failed to load projects', 'error');
            console.error('Error loading projects:', error);
        }
    }

    renderProjectSelect() {
        this.projectSelect.innerHTML = this.projects
            .map(p => `<option value="${p.id}">${this.escapeHtml(p.name)}</option>`)
            .join('');
    }

    currentProject() {
        return this.projects.find(p => String(p.id) === String(this.currentProjectId)) || null;
    }

    updateProjectControls() {
        const project = this.currentProject();
        const deletable = Boolean(project && project.deletable);

        this.btnDeleteProject.disabled = !deletable;
        this.btnDeleteProject.title = project && !deletable
            ? `Project "${project.name}" cannot be deleted`
            : 'Delete project';
    }

    switchProject(projectId) {
        this.currentProjectId = projectId;
        localStorage.setItem(PROJECT_ID_STORAGE_KEY, projectId);
        this.onProjectChanged();
    }

    onProjectChanged() {
        this.updateProjectControls();
        this.resetSearch();
        this.resetToInitialState();

        const activeView = Object.entries(this.views).find(([, view]) => view.classList.contains('active'));
        if (activeView && activeView[0] === 'manage') {
            this.loadActivities();
        }
    }

    toggleAddProjectForm(show) {
        if (show) {
            this.addProjectFormContainer.classList.remove('hidden');
            this.newProjectName.focus();
        } else {
            this.addProjectFormContainer.classList.add('hidden');
            this.newProjectName.value = '';
        }
    }

    async addProject() {
        const name = this.newProjectName.value.trim();

        if (!name) {
            this.showNotification('Project name is required', 'error');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/projects`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name }),
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(
                    result.data.restored
                        ? `Project "${result.data.name}" restored`
                        : `Project "${name}" added`,
                    'success',
                );
                this.toggleAddProjectForm(false);

                // loadProjects() honours the stored id, so the new project ends
                // up selected while the list keeps the server's ordering.
                localStorage.setItem(PROJECT_ID_STORAGE_KEY, String(result.data.id));
                await this.loadProjects();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to add project', 'error');
            console.error('Error adding project:', error);
        }
    }

    async deleteProject() {
        const project = this.currentProject();

        if (!project || !project.deletable) {
            return;
        }

        const confirmed = await this.showConfirmDialog(
            `Delete project "${project.name}"? It is archived rather than erased, `
            + 'so its activities stay in the database and re-adding the same name brings it back.',
        );

        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/projects/${encodeURIComponent(project.id)}`, {
                method: 'DELETE',
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(`Project "${project.name}" deleted`, 'success');
                await this.loadProjects();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to delete project', 'error');
            console.error('Error deleting project:', error);
        }
    }

    async getNextSuggestion() {
        this.showLoadingState();
        this.setActionButtonsEnabled(false);

        try {
            const response = await fetch(`${API_BASE}/activities/suggest?project_id=${this.currentProjectId}`);
            const result = await response.json();

            if (result.success) {
                this.currentSuggestion = result.data;
                this.displaySuggestion(result.data);
                this.setActionButtonsEnabled(true);
            } else {
                this.showNotification(result.error, 'error');
                this.currentSuggestion = null;
                this.setActionButtonsEnabled(false);
                this.btnNext.disabled = false;
            }
        } catch (error) {
            this.showNotification('Failed to get suggestion', 'error');
            console.error('Error getting suggestion:', error);
            this.setActionButtonsEnabled(false);
            this.btnNext.disabled = false;
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

    setActionButtonsEnabled(enabled) {
        this.btnThumbsDown.disabled = !enabled;
        this.btnNext.disabled = !enabled;
        this.btnThumbsUp.disabled = !enabled;
        this.btnDeleteSuggestion.disabled = !enabled;
    }

    resetToInitialState() {
        this.currentSuggestion = null;
        this.suggestionContent.innerHTML = '<p class="placeholder">Click "Get Suggestion" to start</p>';
        this.setActionButtonsEnabled(false);
        this.btnNext.disabled = false;
    }

    showConfirmDialog(message) {
        this.confirmDialogMessage.textContent = message;
        this.confirmDialogOverlay.classList.remove('hidden');

        return new Promise(resolve => {
            this.confirmDialogResolve = resolve;
        });
    }

    resolveConfirmDialog(confirmed) {
        this.confirmDialogOverlay.classList.add('hidden');

        if (this.confirmDialogResolve) {
            this.confirmDialogResolve(confirmed);
            this.confirmDialogResolve = null;
        }
    }

    async deleteCurrentSuggestion() {
        if (!this.currentSuggestion) return;

        const name = this.currentSuggestion.activity;
        const confirmed = await this.showConfirmDialog(`Delete activity "${name}"?`);
        if (!confirmed) return;

        try {
            const response = await fetch(
                `${API_BASE}/activities/${encodeURIComponent(name)}?project_id=${this.currentProjectId}`,
                { method: 'DELETE' },
            );

            const result = await response.json();

            if (result.success) {
                this.showNotification(`Activity "${name}" deleted`, 'success');
                this.resetToInitialState();
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to delete activity', 'error');
            console.error('Error deleting activity:', error);
        }
    }

    async adjustPriority(delta) {
        if (!this.currentSuggestion) return;

        try {
            const response = await fetch(
                `${API_BASE}/activities/${encodeURIComponent(this.currentSuggestion.activity)}/priority`,
                {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ delta, project_id: this.currentProjectId }),
                },
            );

            const result = await response.json();

            if (result.success) {
                this.showNotification(
                    `Priority ${delta > 0 ? 'increased' : 'decreased'} to ${result.data.priority.toFixed(1)}`,
                    'success',
                );
                if (delta > 0) {
                    this.resetToInitialState();
                } else {
                    this.getNextSuggestion();
                }
            } else {
                this.showNotification(result.error, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to adjust priority', 'error');
            console.error('Error adjusting priority:', error);
        }
    }

    onSearchInput() {
        this.searchQuery = this.activitySearch.value;
        this.btnClearSearch.classList.toggle('hidden', this.searchQuery === '');

        clearTimeout(this.searchDebounce);
        this.searchDebounce = setTimeout(() => this.loadActivities(), SEARCH_DEBOUNCE_MS);
    }

    clearSearch() {
        if (this.searchQuery === '' && this.activitySearch.value === '') {
            return;
        }

        this.resetSearch();
        this.loadActivities();
        this.activitySearch.focus();
    }

    resetSearch() {
        clearTimeout(this.searchDebounce);
        this.searchQuery = '';
        this.activitySearch.value = '';
        this.btnClearSearch.classList.add('hidden');
    }

    async loadActivities() {
        // The query this request is for, so a slow response for an older one
        // cannot overwrite the list the user is looking at now
        const query = this.searchQuery;

        try {
            this.activitiesList.innerHTML = '<p class="loading">Loading activities...</p>';

            const params = new URLSearchParams({ project_id: this.currentProjectId });
            if (query !== '') {
                params.set('q', query);
            }

            const response = await fetch(`${API_BASE}/activities?${params}`);
            const result = await response.json();

            if (query !== this.searchQuery) {
                return;
            }

            if (result.success) {
                this.renderActivities(result.data, query);
            } else {
                this.activitiesList.innerHTML = `<p class="loading">${result.error}</p>`;
            }
        } catch (error) {
            if (query !== this.searchQuery) {
                return;
            }
            this.activitiesList.innerHTML = '<p class="loading">Failed to load activities</p>';
            console.error('Error loading activities:', error);
        }
    }

    renderActivities(activities, query = '') {
        if (activities.length === 0) {
            this.activitiesList.innerHTML = query === ''
                ? '<p class="loading">No activities yet. Add one to get started!</p>'
                : `<p class="loading">No activity matches "${this.escapeHtml(query)}"</p>`;
            return;
        }

        // Without a query the API returns the plain list, so sort it here as
        // before. With one it comes back best match first, which we keep.
        if (query === '') {
            activities.sort((a, b) => b.priority - a.priority);
        }

        this.activitiesList.innerHTML = activities
            .map(
                activity => `
            <div class="activity-item" data-name="${this.escapeHtml(activity.activity)}">
                <div class="activity-info">
                    <div class="activity-item-name">${this.highlightMatch(activity.activity, activity.matched)}</div>
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

    /**
     * Wrap the characters the API reported as matched in <mark>. The offsets
     * count characters, so the text is walked the same way.
     */
    highlightMatch(text, matched) {
        if (!matched || matched.length === 0) {
            return this.escapeHtml(text);
        }

        const positions = new Set(matched);
        let html = '';
        let open = false;

        Array.from(text).forEach((char, index) => {
            const isMatch = positions.has(index);

            if (isMatch && !open) {
                html += '<mark>';
                open = true;
            } else if (!isMatch && open) {
                html += '</mark>';
                open = false;
            }

            html += this.escapeHtml(char);
        });

        return open ? `${html}</mark>` : html;
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
                body: JSON.stringify({ name, priority, project_id: this.currentProjectId }),
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
        const confirmed = await this.showConfirmDialog(`Delete activity "${name}"?`);
        if (!confirmed) return;

        try {
            const response = await fetch(
                `${API_BASE}/activities/${encodeURIComponent(name)}?project_id=${this.currentProjectId}`,
                { method: 'DELETE' },
            );

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
