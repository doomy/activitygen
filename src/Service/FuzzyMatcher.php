<?php

namespace App\Service;

/**
 * Fuzzy (subsequence) matching with fzf-style scoring.
 *
 * A query matches when all of its characters appear in the subject in order,
 * not necessarily next to each other: "wtt" matches "Water the tomatoes". The
 * score rewards matches that start a word, that run without gaps, and that
 * cover a short stretch of the subject, so the most obvious match ranks first.
 *
 * This lives in the service layer on purpose: both frontends search through
 * GET /api/activities, so they rank and highlight results identically.
 *
 * Matching is case-insensitive for ASCII and UTF-8 aware throughout; returned
 * offsets are character positions, not byte offsets.
 */
final class FuzzyMatcher
{
    private const SCORE_MATCH = 16;
    private const BONUS_BOUNDARY = 8;
    private const BONUS_CAMEL = 7;
    private const BONUS_CONSECUTIVE = 4;
    private const PENALTY_GAP_START = 3;
    private const PENALTY_GAP_EXTENSION = 1;

    /**
     * Latin letters with diacritics folded to their ASCII base, so that
     * "cesky" finds "cesky" spelled with its diacritics. Spelled out rather
     * than left to iconv()/intl, whose transliteration depends on the C
     * library and locale of whichever image the app runs in.
     *
     * Every entry maps one character to exactly one character: folding must
     * not change the character count, or the offsets the frontends highlight
     * would no longer line up.
     */
    private const FOLDED_CHARS = [
        'à' => 'a', 'À' => 'a', 'á' => 'a', 'Á' => 'a', 'â' => 'a', 'Â' => 'a',
        'ã' => 'a', 'Ã' => 'a', 'ä' => 'a', 'Ä' => 'a', 'å' => 'a', 'Å' => 'a',
        'ą' => 'a', 'Ą' => 'a', 'ă' => 'a', 'Ă' => 'a', 'ā' => 'a', 'Ā' => 'a',
        'æ' => 'a', 'Æ' => 'a',
        'ç' => 'c', 'Ç' => 'c', 'ć' => 'c', 'Ć' => 'c', 'č' => 'c', 'Č' => 'c',
        'ĉ' => 'c', 'Ĉ' => 'c', 'ċ' => 'c', 'Ċ' => 'c',
        'ď' => 'd', 'Ď' => 'd', 'đ' => 'd', 'Đ' => 'd',
        'è' => 'e', 'È' => 'e', 'é' => 'e', 'É' => 'e', 'ê' => 'e', 'Ê' => 'e',
        'ë' => 'e', 'Ë' => 'e', 'ě' => 'e', 'Ě' => 'e', 'ę' => 'e', 'Ę' => 'e',
        'ė' => 'e', 'Ė' => 'e', 'ē' => 'e', 'Ē' => 'e', 'ĕ' => 'e', 'Ĕ' => 'e',
        'ğ' => 'g', 'Ğ' => 'g', 'ģ' => 'g', 'Ģ' => 'g', 'ĝ' => 'g', 'Ĝ' => 'g',
        'ì' => 'i', 'Ì' => 'i', 'í' => 'i', 'Í' => 'i', 'î' => 'i', 'Î' => 'i',
        'ï' => 'i', 'Ï' => 'i', 'į' => 'i', 'Į' => 'i', 'ī' => 'i', 'Ī' => 'i',
        'ĭ' => 'i', 'Ĭ' => 'i',
        'ĺ' => 'l', 'Ĺ' => 'l', 'ľ' => 'l', 'Ľ' => 'l', 'ł' => 'l', 'Ł' => 'l',
        'ļ' => 'l', 'Ļ' => 'l',
        'ñ' => 'n', 'Ñ' => 'n', 'ń' => 'n', 'Ń' => 'n', 'ň' => 'n', 'Ň' => 'n',
        'ņ' => 'n', 'Ņ' => 'n',
        'ò' => 'o', 'Ò' => 'o', 'ó' => 'o', 'Ó' => 'o', 'ô' => 'o', 'Ô' => 'o',
        'õ' => 'o', 'Õ' => 'o', 'ö' => 'o', 'Ö' => 'o', 'ő' => 'o', 'Ő' => 'o',
        'ø' => 'o', 'Ø' => 'o', 'ō' => 'o', 'Ō' => 'o', 'ŏ' => 'o', 'Ŏ' => 'o',
        'œ' => 'o', 'Œ' => 'o',
        'ŕ' => 'r', 'Ŕ' => 'r', 'ř' => 'r', 'Ř' => 'r', 'ŗ' => 'r', 'Ŗ' => 'r',
        'ś' => 's', 'Ś' => 's', 'š' => 's', 'Š' => 's', 'ş' => 's', 'Ş' => 's',
        'ŝ' => 's', 'Ŝ' => 's', 'ß' => 's',
        'ť' => 't', 'Ť' => 't', 'ţ' => 't', 'Ţ' => 't', 'ŧ' => 't', 'Ŧ' => 't',
        'ù' => 'u', 'Ù' => 'u', 'ú' => 'u', 'Ú' => 'u', 'û' => 'u', 'Û' => 'u',
        'ü' => 'u', 'Ü' => 'u', 'ů' => 'u', 'Ů' => 'u', 'ű' => 'u', 'Ű' => 'u',
        'ų' => 'u', 'Ų' => 'u', 'ū' => 'u', 'Ū' => 'u', 'ŭ' => 'u', 'Ŭ' => 'u',
        'ý' => 'y', 'Ý' => 'y', 'ÿ' => 'y', 'Ÿ' => 'y', 'ŷ' => 'y', 'Ŷ' => 'y',
        'ź' => 'z', 'Ź' => 'z', 'ż' => 'z', 'Ż' => 'z', 'ž' => 'z', 'Ž' => 'z',
    ];

    /**
     * Rank a list of activities against a query.
     *
     * Non-matching activities are dropped; the rest come back best match
     * first, each with the character offsets that matched. Ties fall back to
     * priority and then name, so the order is stable. An empty query returns
     * the list untouched.
     *
     * Both frontends filter the list they already hold rather than asking the
     * API per keystroke, so this is mirrored in public/fuzzy.js. Keep the two
     * in step.
     *
     * @param array<array{activity: string, priority: float}> $activities
     * @return array<array{activity: string, priority: float, matched?: int[]}>
     */
    public static function rank(string $query, array $activities): array
    {
        $query = trim($query);

        if ($query === '') {
            return $activities;
        }

        $ranked = [];

        foreach ($activities as $activity) {
            $match = self::match($query, $activity['activity']);

            if ($match === null) {
                continue;
            }

            $activity['matched'] = $match['matched'];
            $ranked[] = ['score' => $match['score'], 'activity' => $activity];
        }

        usort($ranked, static function (array $a, array $b) {
            return $b['score'] <=> $a['score']
                ?: (float)$b['activity']['priority'] <=> (float)$a['activity']['priority']
                ?: strcmp($a['activity']['activity'], $b['activity']['activity']);
        });

        return array_column($ranked, 'activity');
    }

    /**
     * Match $query against $subject.
     *
     * @return array{score: int, matched: int[]}|null Null when it does not match
     */
    public static function match(string $query, string $subject): ?array
    {
        $queryChars = self::chars($query);
        $subjectChars = self::chars($subject);

        if ($queryChars === []) {
            return ['score' => 0, 'matched' => []];
        }

        if (count($queryChars) > count($subjectChars)) {
            return null;
        }

        $foldedQuery = array_map([self::class, 'fold'], $queryChars);
        $foldedSubject = array_map([self::class, 'fold'], $subjectChars);

        $end = self::findMatchEnd($foldedQuery, $foldedSubject);

        if ($end === null) {
            return null;
        }

        $start = self::tightenStart($foldedQuery, $foldedSubject, $end);

        return self::score($foldedQuery, $foldedSubject, $subjectChars, $start, $end);
    }

    /**
     * First position at which the whole query has been matched, scanning left
     * to right.
     *
     * @param string[] $query
     * @param string[] $subject
     */
    private static function findMatchEnd(array $query, array $subject): ?int
    {
        $queryIndex = 0;

        foreach ($subject as $index => $char) {
            if ($char !== $query[$queryIndex]) {
                continue;
            }

            if (++$queryIndex === count($query)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * Walking back from the end of the match pulls the start as far right as
     * possible, so "ate" against "Water the tomatoes" is scored on the tight
     * "ate" in "Water" rather than on a stretch spanning the whole string.
     *
     * @param string[] $query
     * @param string[] $subject
     */
    private static function tightenStart(array $query, array $subject, int $end): int
    {
        $queryIndex = count($query) - 1;

        for ($index = $end - 1; $index >= 0; $index--) {
            if ($subject[$index] !== $query[$queryIndex]) {
                continue;
            }

            if (--$queryIndex < 0) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @param string[] $query
     * @param string[] $subject Case-folded
     * @param string[] $original Subject as written, for camelCase detection
     * @return array{score: int, matched: int[]}
     */
    private static function score(array $query, array $subject, array $original, int $start, int $end): array
    {
        $score = 0;
        $matched = [];
        $queryIndex = 0;
        $consecutive = 0;
        $firstBonus = 0;
        $inGap = false;

        for ($index = $start; $index < $end; $index++) {
            $isMatch = $queryIndex < count($query) && $subject[$index] === $query[$queryIndex];

            if (!$isMatch) {
                $score -= $inGap ? self::PENALTY_GAP_EXTENSION : self::PENALTY_GAP_START;
                $inGap = true;
                $consecutive = 0;
                $firstBonus = 0;
                continue;
            }

            $bonus = self::bonus(
                $index > 0 ? $original[$index - 1] : null,
                $original[$index],
            );

            if ($consecutive === 0) {
                $firstBonus = $bonus;
                // Opening on a word boundary is what makes a match feel right
                if ($index === $start) {
                    $bonus *= 2;
                }
            } else {
                // Inside a run, keep the strongest boundary the run started on
                if ($bonus >= self::BONUS_BOUNDARY && $bonus > $firstBonus) {
                    $firstBonus = $bonus;
                }
                $bonus = max($bonus, $firstBonus, self::BONUS_CONSECUTIVE);
            }

            $score += self::SCORE_MATCH + $bonus;
            $matched[] = $index;
            $consecutive++;
            $queryIndex++;
            $inGap = false;
        }

        return ['score' => $score, 'matched' => $matched];
    }

    /**
     * How much a match at this position is worth beyond the plain match score.
     */
    private static function bonus(?string $previous, string $current): int
    {
        if ($previous === null) {
            return self::BONUS_BOUNDARY;
        }

        if (!self::isAlphanumeric($previous)) {
            return self::BONUS_BOUNDARY;
        }

        if (self::isLower($previous) && self::isUpper($current)) {
            return self::BONUS_CAMEL;
        }

        if (!self::isDigit($previous) && self::isDigit($current)) {
            return self::BONUS_CAMEL;
        }

        return 0;
    }

    /**
     * Split into characters rather than bytes, so offsets line up with what
     * the frontends highlight.
     *
     * @return string[]
     */
    private static function chars(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }

    /**
     * Case-fold a single character, and strip a diacritic if it carries one.
     * No mbstring dependency, in keeping with the rest of the code base.
     */
    private static function fold(string $char): string
    {
        if (strlen($char) === 1) {
            return strtolower($char);
        }

        return self::FOLDED_CHARS[$char] ?? $char;
    }

    private static function isAlphanumeric(string $char): bool
    {
        // Non-ASCII letters count as word characters, so "ž" is not a boundary
        return strlen($char) > 1 || ctype_alnum($char);
    }

    private static function isLower(string $char): bool
    {
        return strlen($char) === 1 && ctype_lower($char);
    }

    private static function isUpper(string $char): bool
    {
        return strlen($char) === 1 && ctype_upper($char);
    }

    private static function isDigit(string $char): bool
    {
        return strlen($char) === 1 && ctype_digit($char);
    }
}
