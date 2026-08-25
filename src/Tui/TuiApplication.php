<?php

namespace App\Tui;

/**
 * Full-screen keyboard-driven TUI mirroring the web frontend (public/app.js).
 *
 * Feature parity with the web UI is a project rule: every feature here exists
 * in the web app and vice versa. Both talk to the same REST API.
 */
class TuiApplication
{
    private const POLL_INTERVAL = 5.0;
    private const NOTIFICATION_TTL = 3.0;
    private const PRIORITY_STEP = 0.1;

    private const MODE_NORMAL = 'normal';
    private const MODE_INPUT = 'input';
    private const MODE_CONFIRM_DELETE = 'confirm_delete';
    private const MODE_PROJECT_PICKER = 'project_picker';

    private const VIEW_SUGGEST = 'suggest';
    private const VIEW_MANAGE = 'manage';

    private ApiClient $api;
    private Terminal $terminal;
    private string $stateFile;

    private bool $running = true;
    private bool $dirty = true;
    private string $view = self::VIEW_SUGGEST;
    private string $mode = self::MODE_NORMAL;

    /** @var array<int, array{id:int|string, name:string}> */
    private array $projects = [];
    private ?int $projectId = null;

    private ?array $suggestion = null;
    private bool $suggestLoading = false;

    private array $activities = [];
    private ?string $activitiesError = null;
    private int $selected = 0;
    private int $scroll = 0;

    private ?bool $online = null;
    private int $pendingOperations = 0;
    private bool $apiReachable = true;
    private float $lastPoll = 0.0;

    /** @var array{message:string, type:string, until:float}|null */
    private ?array $notification = null;

    private string $inputFlow = '';
    private string $inputStep = '';
    private string $inputBuffer = '';
    private array $inputValues = [];
    private int $pickerIndex = 0;

    public function __construct(ApiClient $api, Terminal $terminal, ?string $stateFile = null)
    {
        $this->api = $api;
        $this->terminal = $terminal;
        $this->stateFile = $stateFile ?? dirname(__DIR__, 2) . '/data/tui_state.json';
    }

    public function run(): int
    {
        $this->terminal->start();

        try {
            $this->bootstrap();
            $this->loop();
        } finally {
            $this->terminal->stop();
        }

        return 0;
    }

    private function bootstrap(): void
    {
        $attempts = 20;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $this->reloadProjects();
                $this->refreshSyncStatus();
                return;
            } catch (ApiException $e) {
                if ($i === $attempts) {
                    throw $e;
                }
                $this->renderMessageFrame(
                    "Connecting to API at {$this->api->getBaseUrl()} (attempt $i/$attempts)..."
                );
                usleep(500_000);
            }
        }
    }

    private function loop(): void
    {
        while ($this->running) {
            if ($this->dirty) {
                $this->render();
                $this->dirty = false;
            }

            $key = $this->terminal->readKey(0.2);
            if ($key !== null) {
                $this->handleKey($key);
            }

            $now = microtime(true);
            if ($now - $this->lastPoll >= self::POLL_INTERVAL) {
                $this->refreshSyncStatus();
            }
            if ($this->notification !== null && $now >= $this->notification['until']) {
                $this->notification = null;
                $this->dirty = true;
            }
        }
    }

    // ------------------------------------------------------------------
    // Data operations (each maps 1:1 to a web-frontend action)
    // ------------------------------------------------------------------

    private function reloadProjects(): void
    {
        $this->projects = array_values($this->api->getProjects());

        $storedId = $this->loadStoredProjectId();
        $ids = array_map(static fn(array $p) => (int)$p['id'], $this->projects);

        if ($storedId !== null && in_array($storedId, $ids, true)) {
            $this->projectId = $storedId;
        } else {
            $this->projectId = $ids[0] ?? null;
        }

        if ($this->projectId !== null) {
            $this->storeProjectId($this->projectId);
        }
        $this->dirty = true;
    }

    private function switchProject(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->storeProjectId($projectId);
        $this->resetSuggestion();
        $this->selected = 0;
        $this->scroll = 0;
        if ($this->view === self::VIEW_MANAGE) {
            $this->loadActivities();
        }
        $this->dirty = true;
    }

    private function addProject(string $name): bool
    {
        try {
            $project = $this->api->addProject($name);
            $this->projects[] = $project;
            $this->notify("Project \"$name\" added", 'success');
            $this->switchProject((int)$project['id']);
            return true;
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');
            return false;
        }
    }

    private function nextSuggestion(): void
    {
        if (!$this->requireProject()) {
            return;
        }

        $this->suggestLoading = true;
        $this->render();
        $this->suggestLoading = false;

        try {
            $this->suggestion = $this->api->getSuggestion($this->projectId);
        } catch (ApiException $e) {
            $this->suggestion = null;
            $this->notify($e->getMessage(), 'error');
        }
        $this->dirty = true;
    }

    private function adjustPriority(float $delta): void
    {
        if ($this->suggestion === null || !$this->requireProject()) {
            return;
        }

        try {
            $result = $this->api->adjustPriority($this->projectId, $this->suggestion['activity'], $delta);
            $newPriority = number_format((float)$result['priority'], 1);
            $direction = $delta > 0 ? 'increased' : 'decreased';
            $this->notify("Priority $direction to $newPriority", 'success');

            // Same behavior as the web UI: upvote resets, downvote advances
            if ($delta > 0) {
                $this->resetSuggestion();
            } else {
                $this->nextSuggestion();
            }
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');
        }
        $this->dirty = true;
    }

    private function resetSuggestion(): void
    {
        $this->suggestion = null;
        $this->suggestLoading = false;
        $this->dirty = true;
    }

    private function loadActivities(): void
    {
        if (!$this->requireProject()) {
            return;
        }

        try {
            $activities = array_values($this->api->getActivities($this->projectId));
            usort($activities, static fn(array $a, array $b) => (float)$b['priority'] <=> (float)$a['priority']);
            $this->activities = $activities;
            $this->activitiesError = null;
        } catch (ApiException $e) {
            $this->activities = [];
            $this->activitiesError = $e->getMessage();
        }

        $this->selected = max(0, min($this->selected, count($this->activities) - 1));
        $this->dirty = true;
    }

    private function addActivity(string $name, float $priority): void
    {
        if (!$this->requireProject()) {
            return;
        }

        try {
            $this->api->addActivity($this->projectId, $name, $priority);
            $this->notify("Activity \"$name\" added", 'success');
            $this->loadActivities();
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');
        }
    }

    private function deleteSelectedActivity(): void
    {
        $activity = $this->activities[$this->selected] ?? null;
        if ($activity === null || !$this->requireProject()) {
            return;
        }

        try {
            $this->api->deleteActivity($this->projectId, $activity['activity']);
            $this->notify("Activity \"{$activity['activity']}\" deleted", 'success');
            $this->loadActivities();
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');
        }
    }

    private function manualSync(): void
    {
        $this->renderMessageFrame('Syncing...');

        try {
            $this->api->sync();
            $this->notify('Sync completed', 'success');
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');
        }

        $this->refreshSyncStatus();
        if ($this->view === self::VIEW_MANAGE) {
            $this->loadActivities();
        }
        $this->dirty = true;
    }

    private function refreshSyncStatus(): void
    {
        $this->lastPoll = microtime(true);

        try {
            $status = $this->api->getSyncStatus();
            $online = (bool)($status['online'] ?? false);
            $pending = (int)($status['pendingOperations'] ?? 0);
            $reachable = true;
        } catch (ApiException $e) {
            $online = false;
            $pending = $this->pendingOperations;
            $reachable = false;
        }

        if ($online !== $this->online || $pending !== $this->pendingOperations || $reachable !== $this->apiReachable) {
            $this->online = $online;
            $this->pendingOperations = $pending;
            $this->apiReachable = $reachable;
            $this->dirty = true;
        }
    }

    private function requireProject(): bool
    {
        if ($this->projectId === null) {
            $this->notify('No project selected — press [p] to create one', 'error');
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Key handling
    // ------------------------------------------------------------------

    private function handleKey(string $key): void
    {
        switch ($this->mode) {
            case self::MODE_INPUT:
                $this->handleInputKey($key);
                return;
            case self::MODE_CONFIRM_DELETE:
                $this->handleConfirmDeleteKey($key);
                return;
            case self::MODE_PROJECT_PICKER:
                $this->handlePickerKey($key);
                return;
        }

        // Global keys
        switch ($key) {
            case 'q':
            case 'Q':
            case Terminal::KEY_CTRL_C:
                $this->running = false;
                return;
            case '1':
                $this->setView(self::VIEW_SUGGEST);
                return;
            case '2':
                $this->setView(self::VIEW_MANAGE);
                return;
            case Terminal::KEY_TAB:
                $this->setView($this->view === self::VIEW_SUGGEST ? self::VIEW_MANAGE : self::VIEW_SUGGEST);
                return;
            case 'p':
            case 'P':
                $this->openProjectPicker();
                return;
            case 's':
            case 'S':
                $this->manualSync();
                return;
            case 'r':
            case 'R':
                try {
                    $this->reloadProjects();
                } catch (ApiException $e) {
                    $this->notify($e->getMessage(), 'error');
                }
                $this->refreshSyncStatus();
                if ($this->view === self::VIEW_MANAGE) {
                    $this->loadActivities();
                }
                return;
        }

        if ($this->view === self::VIEW_SUGGEST) {
            $this->handleSuggestKey($key);
        } else {
            $this->handleManageKey($key);
        }
    }

    private function handleSuggestKey(string $key): void
    {
        switch ($key) {
            case ' ':
            case 'n':
            case 'g':
            case Terminal::KEY_ENTER:
                $this->nextSuggestion();
                return;
            case '+':
            case '=':
                $this->adjustPriority(self::PRIORITY_STEP);
                return;
            case '-':
            case '_':
                $this->adjustPriority(-self::PRIORITY_STEP);
                return;
        }
    }

    private function handleManageKey(string $key): void
    {
        switch ($key) {
            case Terminal::KEY_UP:
            case 'k':
                $this->moveSelection(-1);
                return;
            case Terminal::KEY_DOWN:
            case 'j':
                $this->moveSelection(1);
                return;
            case 'a':
            case 'A':
                $this->startInput('add_activity', 'name');
                return;
            case 'd':
            case 'D':
            case 'x':
            case Terminal::KEY_DELETE:
                if (isset($this->activities[$this->selected])) {
                    $this->mode = self::MODE_CONFIRM_DELETE;
                    $this->dirty = true;
                }
                return;
        }
    }

    private function handleConfirmDeleteKey(string $key): void
    {
        $this->mode = self::MODE_NORMAL;
        if ($key === 'y' || $key === 'Y') {
            $this->deleteSelectedActivity();
        }
        $this->dirty = true;
    }

    private function openProjectPicker(): void
    {
        $this->mode = self::MODE_PROJECT_PICKER;
        $this->pickerIndex = 0;
        foreach ($this->projects as $i => $project) {
            if ((int)$project['id'] === $this->projectId) {
                $this->pickerIndex = $i;
                break;
            }
        }
        $this->dirty = true;
    }

    private function handlePickerKey(string $key): void
    {
        $count = count($this->projects);

        switch ($key) {
            case Terminal::KEY_UP:
            case 'k':
                if ($count > 0) {
                    $this->pickerIndex = ($this->pickerIndex - 1 + $count) % $count;
                    $this->dirty = true;
                }
                return;
            case Terminal::KEY_DOWN:
            case 'j':
                if ($count > 0) {
                    $this->pickerIndex = ($this->pickerIndex + 1) % $count;
                    $this->dirty = true;
                }
                return;
            case Terminal::KEY_ENTER:
                if (isset($this->projects[$this->pickerIndex])) {
                    $this->mode = self::MODE_NORMAL;
                    $this->switchProject((int)$this->projects[$this->pickerIndex]['id']);
                }
                return;
            case 'a':
            case 'n':
            case '+':
                $this->startInput('add_project', 'name');
                return;
            case Terminal::KEY_ESC:
            case 'p':
            case 'q':
                $this->mode = self::MODE_NORMAL;
                $this->dirty = true;
                return;
        }
    }

    private function startInput(string $flow, string $step): void
    {
        $this->mode = self::MODE_INPUT;
        $this->inputFlow = $flow;
        $this->inputStep = $step;
        $this->inputBuffer = '';
        $this->inputValues = [];
        $this->dirty = true;
    }

    private function handleInputKey(string $key): void
    {
        switch ($key) {
            case Terminal::KEY_ESC:
            case Terminal::KEY_CTRL_C:
                // Cancelling "add project" returns to the picker it came from
                $this->mode = $this->inputFlow === 'add_project' ? self::MODE_PROJECT_PICKER : self::MODE_NORMAL;
                $this->dirty = true;
                return;
            case Terminal::KEY_ENTER:
                $this->submitInputStep();
                return;
            case Terminal::KEY_BACKSPACE:
                $this->inputBuffer = $this->removeLastChar($this->inputBuffer);
                $this->dirty = true;
                return;
        }

        // Append printable bytes (UTF-8 continuation bytes included)
        if (strlen($key) === 1 && ord($key) >= 0x20) {
            $this->inputBuffer .= $key;
            $this->dirty = true;
        }
    }

    private function submitInputStep(): void
    {
        $value = trim($this->inputBuffer);

        if ($this->inputFlow === 'add_project') {
            if ($value === '') {
                $this->notify('Project name is required', 'error');
                return;
            }
            if ($this->addProject($value)) {
                $this->mode = self::MODE_NORMAL;
            } else {
                $this->mode = self::MODE_PROJECT_PICKER;
            }
            $this->dirty = true;
            return;
        }

        // add_activity: name step, then priority step
        if ($this->inputStep === 'name') {
            if ($value === '') {
                $this->notify('Activity name is required', 'error');
                return;
            }
            $this->inputValues['name'] = $value;
            $this->inputStep = 'priority';
            $this->inputBuffer = '';
            $this->dirty = true;
            return;
        }

        if ($value !== '' && !is_numeric($value)) {
            $this->notify('Priority must be a number', 'error');
            return;
        }

        $priority = $value === '' ? 1.0 : (float)$value;
        $this->mode = self::MODE_NORMAL;
        $this->addActivity($this->inputValues['name'], $priority);
        $this->dirty = true;
    }

    private function moveSelection(int $delta): void
    {
        $count = count($this->activities);
        if ($count === 0) {
            return;
        }
        $this->selected = max(0, min($count - 1, $this->selected + $delta));
        $this->dirty = true;
    }

    private function setView(string $view): void
    {
        if ($this->view === $view) {
            return;
        }
        $this->view = $view;
        if ($view === self::VIEW_MANAGE) {
            $this->loadActivities();
        }
        $this->dirty = true;
    }

    private function notify(string $message, string $type): void
    {
        $this->notification = [
            'message' => $message,
            'type' => $type,
            'until' => microtime(true) + self::NOTIFICATION_TTL,
        ];
        $this->dirty = true;
    }

    // ------------------------------------------------------------------
    // State persistence (TUI counterpart of the web app's localStorage)
    // ------------------------------------------------------------------

    private function loadStoredProjectId(): ?int
    {
        if (!is_file($this->stateFile)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($this->stateFile), true);

        return isset($data['project_id']) ? (int)$data['project_id'] : null;
    }

    private function storeProjectId(int $projectId): void
    {
        @file_put_contents($this->stateFile, json_encode(['project_id' => $projectId]));
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function render(): void
    {
        [$w, $h] = $this->terminal->size();
        $w = max(40, $w);
        $h = max(12, $h);

        $lines = [];
        $lines[] = $this->renderHeader($w);
        $lines[] = $this->fit(' Project: ' . $this->currentProjectName(), $w);
        $lines[] = $this->renderTabs($w);
        $lines[] = str_repeat('─', $w);

        $bodyHeight = $h - 8;
        foreach ($this->renderBody($w, $bodyHeight) as $line) {
            $lines[] = $line;
        }

        $lines[] = str_repeat('─', $w);
        $lines[] = $this->renderNotification($w);
        $lines[] = "\e[2m" . $this->fit(' ' . $this->footerText(), $w) . "\e[0m";

        $frame = "\e[H" . implode("\e[K\r\n", $lines) . "\e[K\e[0J";
        $this->terminal->draw($frame);
    }

    private function renderHeader(int $w): string
    {
        $left = ' ActivityGen';

        if (!$this->apiReachable) {
            $badge = 'API UNREACHABLE';
            $color = "\e[31m";
        } elseif ($this->online === true) {
            $badge = '● ONLINE';
            $color = "\e[32m";
        } else {
            $badge = '● OFFLINE';
            $color = "\e[31m";
        }
        if ($this->pendingOperations > 0) {
            $badge .= " · {$this->pendingOperations} pending";
        }
        $badge .= ' ';

        $pad = max(1, $w - $this->uwidth($left) - $this->uwidth($badge));

        return "\e[1m" . $left . "\e[0m" . str_repeat(' ', $pad) . $color . $badge . "\e[0m";
    }

    private function renderTabs(int $w): string
    {
        $suggestActive = $this->view === self::VIEW_SUGGEST;
        $tab = static function (string $label, bool $active): string {
            return $active
                ? "\e[7m $label \e[0m"
                : "\e[2m $label \e[0m";
        };

        $plain = ' [1] Suggestions  [2] Manage Activities';
        $line = ' ' . $tab('[1] Suggestions', $suggestActive) . ' ' . $tab('[2] Manage Activities', !$suggestActive);
        $pad = max(0, $w - $this->uwidth($plain) - 2);

        return $line . str_repeat(' ', $pad);
    }

    /**
     * @return string[] exactly $height lines
     */
    private function renderBody(int $w, int $height): array
    {
        $lines = match ($this->mode) {
            self::MODE_PROJECT_PICKER => $this->renderProjectPicker($w, $height),
            self::MODE_INPUT => $this->renderInputForm($w),
            self::MODE_CONFIRM_DELETE => $this->renderConfirmDelete($w),
            default => $this->view === self::VIEW_SUGGEST
                ? $this->renderSuggestView($w, $height)
                : $this->renderManageView($w, $height),
        };

        $lines = array_slice($lines, 0, $height);
        while (count($lines) < $height) {
            $lines[] = '';
        }

        return $lines;
    }

    private function renderSuggestView(int $w, int $height): array
    {
        $lines = [];
        $top = max(1, intdiv($height, 3));
        for ($i = 0; $i < $top; $i++) {
            $lines[] = '';
        }

        if ($this->suggestLoading) {
            $lines[] = $this->center('Finding next activity...', $w);
        } elseif ($this->suggestion !== null) {
            $name = (string)$this->suggestion['activity'];
            $priority = number_format((float)$this->suggestion['priority'], 1);
            $minRoll = number_format((float)$this->suggestion['minRoll'], 1);

            $lines[] = "\e[1;36m" . $this->center($name, $w) . "\e[0m";
            $lines[] = '';
            $lines[] = $this->center("Priority: $priority    Min Roll: $minRoll", $w);
            $lines[] = '';
            $lines[] = "\e[2m" . $this->center('[-] thumbs down    [space] next    [+] thumbs up', $w) . "\e[0m";
        } else {
            $lines[] = "\e[2m" . $this->center('Press [space] to get a suggestion', $w) . "\e[0m";
        }

        return $lines;
    }

    private function renderManageView(int $w, int $height): array
    {
        $lines = [];

        if ($this->activitiesError !== null) {
            $lines[] = '';
            $lines[] = "\e[31m" . $this->fit(' ' . $this->activitiesError, $w) . "\e[0m";
            return $lines;
        }

        $count = count($this->activities);
        $lines[] = "\e[1m" . $this->fit(" Activities ($count)", $w) . "\e[0m";

        if ($count === 0) {
            $lines[] = '';
            $lines[] = "\e[2m" . $this->fit(' No activities yet. Press [a] to add one!', $w) . "\e[0m";
            return $lines;
        }

        $listHeight = max(1, $height - 1);
        $this->adjustScroll($listHeight);

        $visible = array_slice($this->activities, $this->scroll, $listHeight, true);
        foreach ($visible as $i => $activity) {
            $name = (string)$activity['activity'];
            $priority = number_format((float)$activity['priority'], 1);
            $row = $this->row('  ' . $name, "priority $priority  ", $w);
            $lines[] = $i === $this->selected ? "\e[7m" . $row . "\e[0m" : $row;
        }

        return $lines;
    }

    private function renderProjectPicker(int $w, int $height): array
    {
        $lines = [];
        $lines[] = "\e[1m" . $this->fit(' Switch project', $w) . "\e[0m";
        $lines[] = '';

        if (count($this->projects) === 0) {
            $lines[] = "\e[2m" . $this->fit(' No projects yet. Press [a] to add one!', $w) . "\e[0m";
            return $lines;
        }

        $listHeight = max(1, $height - 2);
        $offset = max(0, min($this->pickerIndex - $listHeight + 1, count($this->projects) - $listHeight));
        $visible = array_slice($this->projects, max(0, $offset), $listHeight, true);

        foreach ($visible as $i => $project) {
            $marker = (int)$project['id'] === $this->projectId ? '● ' : '  ';
            $row = $this->fit('  ' . $marker . $project['name'], $w);
            $lines[] = $i === $this->pickerIndex ? "\e[7m" . $row . "\e[0m" : $row;
        }

        return $lines;
    }

    private function renderInputForm(int $w): array
    {
        $lines = [''];

        if ($this->inputFlow === 'add_project') {
            $lines[] = "\e[1m" . $this->fit(' Add project', $w) . "\e[0m";
            $lines[] = '';
            $lines[] = $this->fit(' Name: ' . $this->inputBuffer . '▌', $w);
            return $lines;
        }

        $lines[] = "\e[1m" . $this->fit(' Add activity', $w) . "\e[0m";
        $lines[] = '';

        if ($this->inputStep === 'name') {
            $lines[] = $this->fit(' Name: ' . $this->inputBuffer . '▌', $w);
        } else {
            $lines[] = $this->fit(' Name: ' . $this->inputValues['name'], $w);
            $lines[] = $this->fit(' Priority (default 1.0): ' . $this->inputBuffer . '▌', $w);
        }

        return $lines;
    }

    private function renderConfirmDelete(int $w): array
    {
        $name = (string)($this->activities[$this->selected]['activity'] ?? '');

        return [
            '',
            "\e[1;31m" . $this->fit(" Delete activity \"$name\"? [y/n]", $w) . "\e[0m",
        ];
    }

    private function renderNotification(int $w): string
    {
        if ($this->notification === null) {
            return '';
        }

        $color = $this->notification['type'] === 'error' ? "\e[41;97m" : "\e[42;30m";

        return $color . $this->fit(' ' . $this->notification['message'] . ' ', $w) . "\e[0m";
    }

    private function footerText(): string
    {
        return match ($this->mode) {
            self::MODE_INPUT => 'enter confirm · esc cancel',
            self::MODE_CONFIRM_DELETE => 'y delete · n cancel',
            self::MODE_PROJECT_PICKER => '↑↓ move · enter select · a add project · esc close',
            default => $this->view === self::VIEW_SUGGEST
                ? 'space next · + up · - down · tab/1/2 view · p project · s sync · r refresh · q quit'
                : '↑↓ move · a add · d delete · tab/1/2 view · p project · s sync · r refresh · q quit',
        };
    }

    private function renderMessageFrame(string $message): void
    {
        [$w, $h] = $this->terminal->size();
        $frame = "\e[H\e[2J" . str_repeat("\r\n", max(0, intdiv($h, 2) - 1)) . $this->center($message, $w);
        $this->terminal->draw($frame);
    }

    private function adjustScroll(int $listHeight): void
    {
        if ($this->selected < $this->scroll) {
            $this->scroll = $this->selected;
        }
        if ($this->selected >= $this->scroll + $listHeight) {
            $this->scroll = $this->selected - $listHeight + 1;
        }
        $this->scroll = max(0, min($this->scroll, max(0, count($this->activities) - $listHeight)));
    }

    private function currentProjectName(): string
    {
        foreach ($this->projects as $project) {
            if ((int)$project['id'] === $this->projectId) {
                return (string)$project['name'];
            }
        }

        return '(none)';
    }

    // ------------------------------------------------------------------
    // String helpers (UTF-8 aware, no mbstring dependency)
    // ------------------------------------------------------------------

    private function uwidth(string $s): int
    {
        $count = preg_match_all('/\X/u', $s);

        return $count === false ? strlen($s) : $count;
    }

    private function fit(string $s, int $w): string
    {
        $width = $this->uwidth($s);
        if ($width > $w) {
            preg_match_all('/\X/u', $s, $m);
            return implode('', array_slice($m[0], 0, max(0, $w - 1))) . '…';
        }

        return $s . str_repeat(' ', $w - $width);
    }

    private function center(string $s, int $w): string
    {
        $width = $this->uwidth($s);
        if ($width >= $w) {
            return $this->fit($s, $w);
        }
        $left = intdiv($w - $width, 2);

        return str_repeat(' ', $left) . $s . str_repeat(' ', $w - $width - $left);
    }

    private function row(string $left, string $right, int $w): string
    {
        $rightWidth = $this->uwidth($right);
        $maxLeft = $w - $rightWidth - 1;
        if ($this->uwidth($left) > $maxLeft) {
            $left = $this->fit($left, $maxLeft);
        }
        $pad = max(1, $w - $this->uwidth($left) - $rightWidth);

        return $left . str_repeat(' ', $pad) . $right;
    }

    private function removeLastChar(string $s): string
    {
        if ($s === '') {
            return '';
        }
        // Strip UTF-8 continuation bytes, then the lead byte
        $len = strlen($s);
        while ($len > 0 && (ord($s[$len - 1]) & 0xC0) === 0x80) {
            $len--;
        }

        return substr($s, 0, max(0, $len - 1));
    }
}
