/**
 * Fuzzy (subsequence) matching with fzf-style scoring.
 *
 * A query matches when all of its characters appear in the subject in order,
 * not necessarily next to each other: "wtt" matches "Water the tomatoes". The
 * score rewards matches that start a word, that run without gaps, and that
 * cover a short stretch of the subject, so the most obvious match ranks first.
 *
 * This is a port of src/Service/FuzzyMatcher.php, which the TUI uses. Both
 * frontends filter the list they already hold rather than asking the API per
 * keystroke, so each needs the matcher in its own language. Keep the two in
 * step: same constants, same scoring, same ordering.
 *
 * Matching is case-insensitive for ASCII, ignores Latin diacritics, and works
 * in characters, so the offsets it reports line up with what gets highlighted.
 */

const FUZZY_SCORE_MATCH = 16;
const FUZZY_BONUS_BOUNDARY = 8;
const FUZZY_BONUS_CAMEL = 7;
const FUZZY_BONUS_CONSECUTIVE = 4;
const FUZZY_PENALTY_GAP_START = 3;
const FUZZY_PENALTY_GAP_EXTENSION = 1;

/**
 * Latin letters with diacritics folded to their ASCII base, so that "cesky"
 * finds "cesky" spelled with its diacritics.
 *
 * Every entry maps one character to exactly one character: folding must not
 * change the character count, or the offsets highlighted would no longer line
 * up. Generated from the same table in the PHP matcher.
 */
const FUZZY_FOLDED_CHARS = {
    'à': 'a', 'À': 'a', 'á': 'a', 'Á': 'a', 'â': 'a', 'Â': 'a',
    'ã': 'a', 'Ã': 'a', 'ä': 'a', 'Ä': 'a', 'å': 'a', 'Å': 'a',
    'ą': 'a', 'Ą': 'a', 'ă': 'a', 'Ă': 'a', 'ā': 'a', 'Ā': 'a',
    'æ': 'a', 'Æ': 'a', 'ç': 'c', 'Ç': 'c', 'ć': 'c', 'Ć': 'c',
    'č': 'c', 'Č': 'c', 'ĉ': 'c', 'Ĉ': 'c', 'ċ': 'c', 'Ċ': 'c',
    'ď': 'd', 'Ď': 'd', 'đ': 'd', 'Đ': 'd', 'è': 'e', 'È': 'e',
    'é': 'e', 'É': 'e', 'ê': 'e', 'Ê': 'e', 'ë': 'e', 'Ë': 'e',
    'ě': 'e', 'Ě': 'e', 'ę': 'e', 'Ę': 'e', 'ė': 'e', 'Ė': 'e',
    'ē': 'e', 'Ē': 'e', 'ĕ': 'e', 'Ĕ': 'e', 'ğ': 'g', 'Ğ': 'g',
    'ģ': 'g', 'Ģ': 'g', 'ĝ': 'g', 'Ĝ': 'g', 'ì': 'i', 'Ì': 'i',
    'í': 'i', 'Í': 'i', 'î': 'i', 'Î': 'i', 'ï': 'i', 'Ï': 'i',
    'į': 'i', 'Į': 'i', 'ī': 'i', 'Ī': 'i', 'ĭ': 'i', 'Ĭ': 'i',
    'ĺ': 'l', 'Ĺ': 'l', 'ľ': 'l', 'Ľ': 'l', 'ł': 'l', 'Ł': 'l',
    'ļ': 'l', 'Ļ': 'l', 'ñ': 'n', 'Ñ': 'n', 'ń': 'n', 'Ń': 'n',
    'ň': 'n', 'Ň': 'n', 'ņ': 'n', 'Ņ': 'n', 'ò': 'o', 'Ò': 'o',
    'ó': 'o', 'Ó': 'o', 'ô': 'o', 'Ô': 'o', 'õ': 'o', 'Õ': 'o',
    'ö': 'o', 'Ö': 'o', 'ő': 'o', 'Ő': 'o', 'ø': 'o', 'Ø': 'o',
    'ō': 'o', 'Ō': 'o', 'ŏ': 'o', 'Ŏ': 'o', 'œ': 'o', 'Œ': 'o',
    'ŕ': 'r', 'Ŕ': 'r', 'ř': 'r', 'Ř': 'r', 'ŗ': 'r', 'Ŗ': 'r',
    'ś': 's', 'Ś': 's', 'š': 's', 'Š': 's', 'ş': 's', 'Ş': 's',
    'ŝ': 's', 'Ŝ': 's', 'ß': 's', 'ť': 't', 'Ť': 't', 'ţ': 't',
    'Ţ': 't', 'ŧ': 't', 'Ŧ': 't', 'ù': 'u', 'Ù': 'u', 'ú': 'u',
    'Ú': 'u', 'û': 'u', 'Û': 'u', 'ü': 'u', 'Ü': 'u', 'ů': 'u',
    'Ů': 'u', 'ű': 'u', 'Ű': 'u', 'ų': 'u', 'Ų': 'u', 'ū': 'u',
    'Ū': 'u', 'ŭ': 'u', 'Ŭ': 'u', 'ý': 'y', 'Ý': 'y', 'ÿ': 'y',
    'Ÿ': 'y', 'ŷ': 'y', 'Ŷ': 'y', 'ź': 'z', 'Ź': 'z', 'ż': 'z',
    'Ż': 'z', 'ž': 'z', 'Ž': 'z',
};

/**
 * Rank a list of activities against a query.
 *
 * Non-matching activities are dropped; the rest come back best match first,
 * each with a `matched` array of character offsets. Ties fall back to priority
 * and then name, so the order is stable. An empty query returns the list
 * untouched.
 */
function fuzzyRank(query, activities) {
    const trimmed = query.trim();

    if (trimmed === '') {
        return activities.slice();
    }

    const ranked = [];

    for (const activity of activities) {
        const match = fuzzyMatch(trimmed, activity.activity);

        if (match === null) {
            continue;
        }

        ranked.push({ score: match.score, activity: { ...activity, matched: match.matched } });
    }

    ranked.sort((a, b) => (
        b.score - a.score
        || b.activity.priority - a.activity.priority
        || compareNames(a.activity.activity, b.activity.activity)
    ));

    return ranked.map(entry => entry.activity);
}

function compareNames(a, b) {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}

/**
 * Match `query` against `subject`.
 *
 * @returns {{score: number, matched: number[]}|null} Null when it does not match
 */
function fuzzyMatch(query, subject) {
    const queryChars = Array.from(query);
    const subjectChars = Array.from(subject);

    if (queryChars.length === 0) {
        return { score: 0, matched: [] };
    }

    if (queryChars.length > subjectChars.length) {
        return null;
    }

    const foldedQuery = queryChars.map(fuzzyFold);
    const foldedSubject = subjectChars.map(fuzzyFold);

    const end = findMatchEnd(foldedQuery, foldedSubject);

    if (end === null) {
        return null;
    }

    const start = tightenStart(foldedQuery, foldedSubject, end);

    return scoreMatch(foldedQuery, foldedSubject, subjectChars, start, end);
}

/**
 * First position at which the whole query has been matched, scanning left to
 * right.
 */
function findMatchEnd(query, subject) {
    let queryIndex = 0;

    for (let index = 0; index < subject.length; index++) {
        if (subject[index] !== query[queryIndex]) {
            continue;
        }

        if (++queryIndex === query.length) {
            return index + 1;
        }
    }

    return null;
}

/**
 * Walking back from the end of the match pulls the start as far right as
 * possible, so "ate" against "Water the tomatoes" is scored on the tight "ate"
 * in "Water" rather than on a stretch spanning the whole string.
 */
function tightenStart(query, subject, end) {
    let queryIndex = query.length - 1;

    for (let index = end - 1; index >= 0; index--) {
        if (subject[index] !== query[queryIndex]) {
            continue;
        }

        if (--queryIndex < 0) {
            return index;
        }
    }

    return 0;
}

function scoreMatch(query, subject, original, start, end) {
    let score = 0;
    const matched = [];
    let queryIndex = 0;
    let consecutive = 0;
    let firstBonus = 0;
    let inGap = false;

    for (let index = start; index < end; index++) {
        const isMatch = queryIndex < query.length && subject[index] === query[queryIndex];

        if (!isMatch) {
            score -= inGap ? FUZZY_PENALTY_GAP_EXTENSION : FUZZY_PENALTY_GAP_START;
            inGap = true;
            consecutive = 0;
            firstBonus = 0;
            continue;
        }

        let bonus = characterBonus(index > 0 ? original[index - 1] : null, original[index]);

        if (consecutive === 0) {
            firstBonus = bonus;
            // Opening on a word boundary is what makes a match feel right
            if (index === start) {
                bonus *= 2;
            }
        } else {
            // Inside a run, keep the strongest boundary the run started on
            if (bonus >= FUZZY_BONUS_BOUNDARY && bonus > firstBonus) {
                firstBonus = bonus;
            }
            bonus = Math.max(bonus, firstBonus, FUZZY_BONUS_CONSECUTIVE);
        }

        score += FUZZY_SCORE_MATCH + bonus;
        matched.push(index);
        consecutive++;
        queryIndex++;
        inGap = false;
    }

    return { score, matched };
}

/**
 * How much a match at this position is worth beyond the plain match score.
 */
function characterBonus(previous, current) {
    if (previous === null) {
        return FUZZY_BONUS_BOUNDARY;
    }

    if (!isAlphanumeric(previous)) {
        return FUZZY_BONUS_BOUNDARY;
    }

    if (isLower(previous) && isUpper(current)) {
        return FUZZY_BONUS_CAMEL;
    }

    if (!isDigit(previous) && isDigit(current)) {
        return FUZZY_BONUS_CAMEL;
    }

    return 0;
}

/**
 * Case-fold a character, and strip a diacritic if it carries one. Only ASCII
 * is case-folded, matching the PHP side.
 */
function fuzzyFold(char) {
    if (isAscii(char)) {
        return char.toLowerCase();
    }

    return FUZZY_FOLDED_CHARS[char] || char;
}

function isAscii(char) {
    return char.codePointAt(0) < 128;
}

function isAlphanumeric(char) {
    // Non-ASCII letters count as word characters, so "z" with a caron is not a boundary
    return !isAscii(char) || /[A-Za-z0-9]/.test(char);
}

function isLower(char) {
    return isAscii(char) && /[a-z]/.test(char);
}

function isUpper(char) {
    return isAscii(char) && /[A-Z]/.test(char);
}

function isDigit(char) {
    return isAscii(char) && /[0-9]/.test(char);
}
