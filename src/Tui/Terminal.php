<?php

namespace App\Tui;

/**
 * Low-level terminal handling: raw mode, alternate screen, key decoding.
 */
class Terminal
{
    public const KEY_UP = 'UP';
    public const KEY_DOWN = 'DOWN';
    public const KEY_LEFT = 'LEFT';
    public const KEY_RIGHT = 'RIGHT';
    public const KEY_ENTER = 'ENTER';
    public const KEY_ESC = 'ESC';
    public const KEY_TAB = 'TAB';
    public const KEY_BACKSPACE = 'BACKSPACE';
    public const KEY_DELETE = 'DEL';
    public const KEY_CTRL_C = 'CTRL_C';

    private ?string $savedSttyState = null;

    public function start(): void
    {
        $this->savedSttyState = trim((string)shell_exec('stty -g 2>/dev/null'));
        // -isig so Ctrl+C arrives as a key we can handle and restore the terminal on
        system('stty -icanon -echo -isig min 0 time 0 2>/dev/null');
        stream_set_blocking(STDIN, false);
        // Alternate screen buffer, hidden cursor
        echo "\e[?1049h\e[?25l\e[H\e[2J";
        flush();
    }

    public function stop(): void
    {
        echo "\e[0m\e[?25h\e[?1049l";
        flush();
        if ($this->savedSttyState) {
            system('stty ' . escapeshellarg($this->savedSttyState) . ' 2>/dev/null');
        } else {
            system('stty sane 2>/dev/null');
        }
        stream_set_blocking(STDIN, true);
    }

    /**
     * @return array{0:int,1:int} [width, height]
     */
    public function size(): array
    {
        $out = trim((string)shell_exec('stty size 2>/dev/null'));
        if (preg_match('/^(\d+)\s+(\d+)$/', $out, $m)) {
            return [(int)$m[2], (int)$m[1]];
        }

        return [80, 24];
    }

    public function draw(string $frame): void
    {
        echo $frame;
        flush();
    }

    /**
     * Waits up to $timeout seconds for a keypress.
     *
     * Returns one of the KEY_* constants for special keys, the raw byte for
     * everything else (UTF-8 characters arrive as their individual bytes),
     * or null when no input arrived within the timeout.
     */
    public function readKey(float $timeout): ?string
    {
        if (!$this->waitForInput($timeout)) {
            return null;
        }

        $char = fread(STDIN, 1);
        if ($char === false || $char === '') {
            return null;
        }

        switch ($char) {
            case "\r":
            case "\n":
                return self::KEY_ENTER;
            case "\t":
                return self::KEY_TAB;
            case "\x7f":
            case "\x08":
                return self::KEY_BACKSPACE;
            case "\x03":
                return self::KEY_CTRL_C;
            case "\e":
                return $this->readEscapeSequence();
            default:
                return $char;
        }
    }

    private function readEscapeSequence(): string
    {
        $seq = '';
        while (strlen($seq) < 8) {
            // The remaining bytes of a sequence arrive almost instantly
            if (!$this->waitForInput(0.01)) {
                break;
            }
            $byte = fread(STDIN, 1);
            if ($byte === false || $byte === '') {
                break;
            }
            $seq .= $byte;
            if (preg_match('/^\[[0-9;]*[A-Za-z~]$/', $seq) || preg_match('/^O[A-Za-z]$/', $seq)) {
                break;
            }
        }

        return match ($seq) {
            '[A', 'OA' => self::KEY_UP,
            '[B', 'OB' => self::KEY_DOWN,
            '[C', 'OC' => self::KEY_RIGHT,
            '[D', 'OD' => self::KEY_LEFT,
            '[3~' => self::KEY_DELETE,
            default => self::KEY_ESC,
        };
    }

    private function waitForInput(float $timeout): bool
    {
        $read = [STDIN];
        $write = null;
        $except = null;
        $sec = (int)floor($timeout);
        $usec = (int)(($timeout - $sec) * 1_000_000);

        return (int)@stream_select($read, $write, $except, $sec, $usec) > 0;
    }
}
