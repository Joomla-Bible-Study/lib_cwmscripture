<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Book;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Book reference data: the numbering schemes and the codes that identify a book.
 *
 * Its own namespace because both `Helper\` and `Bible\` need it and neither
 * should own it. These tables used to live on `AbstractBibleProvider`, which put
 * reference data on a provider base class and meant `Helper\ScriptureReference`
 * could not reach a book code without `Helper -> Bible -> Helper` (issue #49).
 *
 * The dependency direction is therefore:
 *
 *     Book  <-  Helper
 *     Book  <-  Bible
 *     Helper <- Bible   (unchanged)
 *
 * ⚠️ Nothing here may depend on `Helper\` or `Bible\`. That is what keeps the
 * graph acyclic, and it is why name-based resolution is *not* here:
 * `AbstractBibleProvider::resolveBookCode()` needs `ScriptureHelper::getBookNumber()`,
 * so it stays where it is. The line is pure number <-> code lives here;
 * anything requiring name resolution does not.
 *
 * @since  __DEPLOY_VERSION__
 */
final class BookCodes
{
    /**
     * Proclaim book numbers (101-166) to standard book numbers (1-66).
     *
     * Stops at 166 on purpose. The deuterocanon has no standard number, so
     * LocalProvider keeps querying `#__bsms_bible_verses.book` over the canon
     * that table actually holds.
     *
     * @var  array<int, int>
     * @since  __DEPLOY_VERSION__
     */
    public const PROCLAIM_TO_STANDARD = [
        101 => 1, 102 => 2, 103 => 3, 104 => 4, 105 => 5,
        106 => 6, 107 => 7, 108 => 8, 109 => 9, 110 => 10,
        111 => 11, 112 => 12, 113 => 13, 114 => 14, 115 => 15,
        116 => 16, 117 => 17, 118 => 18, 119 => 19, 120 => 20,
        121 => 21, 122 => 22, 123 => 23, 124 => 24, 125 => 25,
        126 => 26, 127 => 27, 128 => 28, 129 => 29, 130 => 30,
        131 => 31, 132 => 32, 133 => 33, 134 => 34, 135 => 35,
        136 => 36, 137 => 37, 138 => 38, 139 => 39, 140 => 40,
        141 => 41, 142 => 42, 143 => 43, 144 => 44, 145 => 45,
        146 => 46, 147 => 47, 148 => 48, 149 => 49, 150 => 50,
        151 => 51, 152 => 52, 153 => 53, 154 => 54, 155 => 55,
        156 => 56, 157 => 57, 158 => 58, 159 => 59, 160 => 60,
        161 => 61, 162 => 62, 163 => 63, 164 => 64, 165 => 65,
        166 => 66,
    ];

    /**
     * English book names by standard book number (1-66).
     *
     * English deliberately: this is the fallback consulted when a localised name
     * fails to resolve, not the name a site displays. `ScriptureHelper::getBookName()`
     * is what returns the translated name.
     *
     * @var  array<int, string>
     * @since  __DEPLOY_VERSION__
     */
    public const BOOK_NAMES = [
        1  => 'Genesis', 2 => 'Exodus', 3 => 'Leviticus', 4 => 'Numbers',
        5  => 'Deuteronomy', 6 => 'Joshua', 7 => 'Judges', 8 => 'Ruth',
        9  => '1 Samuel', 10 => '2 Samuel', 11 => '1 Kings', 12 => '2 Kings',
        13 => '1 Chronicles', 14 => '2 Chronicles', 15 => 'Ezra',
        16 => 'Nehemiah', 17 => 'Esther', 18 => 'Job', 19 => 'Psalms',
        20 => 'Proverbs', 21 => 'Ecclesiastes', 22 => 'Song of Solomon',
        23 => 'Isaiah', 24 => 'Jeremiah', 25 => 'Lamentations',
        26 => 'Ezekiel', 27 => 'Daniel', 28 => 'Hosea', 29 => 'Joel',
        30 => 'Amos', 31 => 'Obadiah', 32 => 'Jonah', 33 => 'Micah',
        34 => 'Nahum', 35 => 'Habakkuk', 36 => 'Zephaniah', 37 => 'Haggai',
        38 => 'Zechariah', 39 => 'Malachi',
        40 => 'Matthew', 41 => 'Mark', 42 => 'Luke', 43 => 'John',
        44 => 'Acts', 45 => 'Romans', 46 => '1 Corinthians',
        47 => '2 Corinthians', 48 => 'Galatians', 49 => 'Ephesians',
        50 => 'Philippians', 51 => 'Colossians', 52 => '1 Thessalonians',
        53 => '2 Thessalonians', 54 => '1 Timothy', 55 => '2 Timothy',
        56 => 'Titus', 57 => 'Philemon', 58 => 'Hebrews', 59 => 'James',
        60 => '1 Peter', 61 => '2 Peter', 62 => '1 John', 63 => '2 John',
        64 => '3 John', 65 => 'Jude', 66 => 'Revelation',
    ];

    /**
     * USFM book codes by standard book number (1-66).
     *
     * USFM, not OSIS. The two agree on the canonical 66 — which is why one table
     * serves both api.bible and Bible Brain — but they disagree on the
     * deuterocanon, where OSIS writes `1Macc` against USFM's `1MA`.
     *
     * @var  array<int, string>
     * @since  __DEPLOY_VERSION__
     */
    public const BOOK_CODES = [
        1  => 'GEN', 2 => 'EXO', 3 => 'LEV', 4 => 'NUM', 5 => 'DEU',
        6  => 'JOS', 7 => 'JDG', 8 => 'RUT', 9 => '1SA', 10 => '2SA',
        11 => '1KI', 12 => '2KI', 13 => '1CH', 14 => '2CH', 15 => 'EZR',
        16 => 'NEH', 17 => 'EST', 18 => 'JOB', 19 => 'PSA', 20 => 'PRO',
        21 => 'ECC', 22 => 'SNG', 23 => 'ISA', 24 => 'JER', 25 => 'LAM',
        26 => 'EZK', 27 => 'DAN', 28 => 'HOS', 29 => 'JOL', 30 => 'AMO',
        31 => 'OBA', 32 => 'JON', 33 => 'MIC', 34 => 'NAM', 35 => 'HAB',
        36 => 'ZEP', 37 => 'HAG', 38 => 'ZEC', 39 => 'MAL',
        40 => 'MAT', 41 => 'MRK', 42 => 'LUK', 43 => 'JHN',
        44 => 'ACT', 45 => 'ROM', 46 => '1CO', 47 => '2CO',
        48 => 'GAL', 49 => 'EPH', 50 => 'PHP', 51 => 'COL',
        52 => '1TH', 53 => '2TH', 54 => '1TI', 55 => '2TI',
        56 => 'TIT', 57 => 'PHM', 58 => 'HEB', 59 => 'JAS',
        60 => '1PE', 61 => '2PE', 62 => '1JN', 63 => '2JN',
        64 => '3JN', 65 => 'JUD', 66 => 'REV',
    ];

    /**
     * USFM codes for the deuterocanon, keyed by *Proclaim* number (167-173).
     *
     * Keyed by Proclaim number because these books have no standard number, so
     * PROCLAIM_TO_STANDARD cannot reach them.
     *
     * ⚠️ The order is not alphabetical and not the order Proclaim#1688 lists:
     * Maccabees sits at 169-170, between Judith and Wisdom. A mapping
     * transcribed from that issue shifts four of the seven onto the wrong book.
     *
     * @var  array<int, string>
     * @since  __DEPLOY_VERSION__
     */
    public const DEUTEROCANON_CODES = [
        167 => 'TOB', 168 => 'JDT', 169 => '1MA', 170 => '2MA',
        171 => 'WIS', 172 => 'SIR', 173 => 'BAR',
    ];

    /**
     * Convert a Proclaim book number (101-166) to standard (1-66).
     *
     * @param   int  $proclaimBook  Proclaim book number
     *
     * @return  int  Standard book number, or 0 when there is no equivalent
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function toStandard(int $proclaimBook): int
    {
        return self::PROCLAIM_TO_STANDARD[$proclaimBook] ?? 0;
    }

    /**
     * Convert a standard book number (1-66) to Proclaim (101-166).
     *
     * Unvalidated, matching the behaviour this replaced: it offsets whatever it
     * is given, so 0 answers 100. Callers rely on that for round-tripping a
     * number they already know is in range, and adding a guard here would change
     * what existing code returns.
     *
     * @param   int  $standardBook  Standard book number
     *
     * @return  int  Proclaim book number
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function toProclaim(int $standardBook): int
    {
        return $standardBook + 100;
    }

    /**
     * The USFM code for a standard book number.
     *
     * @param   int  $standardBook  Standard book number (1-66)
     *
     * @return  string  Book code, or '' when out of range
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function code(int $standardBook): string
    {
        return self::BOOK_CODES[$standardBook] ?? '';
    }

    /**
     * The USFM code for a Proclaim book number (101-173).
     *
     * @param   int  $proclaimBook  Proclaim book number
     *
     * @return  string  Book code, or '' when there is no standard equivalent
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function forProclaim(int $proclaimBook): string
    {
        // The deuterocanon first: it has no standard number, so toStandard()
        // answers 0 for 167-173 and the book would be lost.
        if (isset(self::DEUTEROCANON_CODES[$proclaimBook])) {
            return self::DEUTEROCANON_CODES[$proclaimBook];
        }

        return self::code(self::toStandard($proclaimBook));
    }

    /**
     * The English name for a standard book number.
     *
     * @param   int  $standardBook  Standard book number (1-66)
     *
     * @return  string  Book name, or '' when out of range
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function name(int $standardBook): string
    {
        return self::BOOK_NAMES[$standardBook] ?? '';
    }

    /**
     * Every English book name, keyed by standard book number.
     *
     * Exposed so callers that need to scan the table — the English fallback in
     * `resolveBookCode()`, and LocalProvider's own name resolution — can do so
     * without reaching for the constant across a namespace.
     *
     * @return  array<int, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function names(): array
    {
        return self::BOOK_NAMES;
    }

    /**
     * Every USFM code, keyed by standard book number.
     *
     * Backs the providers' `getOsisCodes()` / `getUsfmCodes()`, which exist so a
     * consumer can see the table without reaching for a protected constant.
     * They return the same array — the two standards agree on the canonical 66.
     *
     * @return  array<int, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function codes(): array
    {
        return self::BOOK_CODES;
    }
}
