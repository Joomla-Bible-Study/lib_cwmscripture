<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Bible;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Value object representing an audio scripture passage result.
 *
 * @since  1.2.0
 */
class AudioPassageResult
{
    /**
     * Audio stream URLs for the passage.
     *
     * Each element: ['url' => string, 'verse_start' => int, 'verse_end' => int, 'duration' => float]
     *
     * @var  array<int, array{url: string, verse_start?: int, verse_end?: int, duration?: float}>
     * @since  1.2.0
     */
    public array $audioFiles;

    /**
     * The USFM book code.
     *
     * @var  string
     * @since  1.2.0
     */
    public string $book;

    /**
     * The chapter number.
     *
     * @var  int
     * @since  1.2.0
     */
    public int $chapter;

    /**
     * The translation/fileset identifier used.
     *
     * @var  string
     * @since  1.2.0
     */
    public string $translation;

    /**
     * Per-verse timing data for synchronized text highlighting.
     *
     * Each element: ['verse' => int, 'timestamp_start' => float, 'timestamp_end' => float]
     *
     * @var  array<int, array{verse: int, timestamp_start: float, timestamp_end: float}>
     * @since  1.2.0
     */
    public array $verseTiming;

    /**
     * Copyright notice for this audio.
     *
     * @var  string
     * @since  1.2.0
     */
    public string $copyright;

    /**
     * Constructor.
     *
     * @param   array   $audioFiles   Audio file URLs and metadata
     * @param   string  $book         USFM book code
     * @param   int     $chapter      Chapter number
     * @param   string  $translation  Translation/fileset identifier
     * @param   array   $verseTiming  Per-verse timing data
     * @param   string  $copyright    Copyright notice
     *
     * @since  1.2.0
     */
    public function __construct(
        array $audioFiles = [],
        string $book = '',
        int $chapter = 0,
        string $translation = '',
        array $verseTiming = [],
        string $copyright = ''
    ) {
        $this->audioFiles  = $audioFiles;
        $this->book        = $book;
        $this->chapter     = $chapter;
        $this->translation = $translation;
        $this->verseTiming = $verseTiming;
        $this->copyright   = $copyright;
    }

    /**
     * Whether the result has audio content.
     *
     * @return  bool
     *
     * @since  1.2.0
     */
    public function hasAudio(): bool
    {
        return !empty($this->audioFiles);
    }

    /**
     * Get the primary audio URL (first file).
     *
     * @return  string  URL or empty string
     *
     * @since  1.2.0
     */
    public function getPrimaryUrl(): string
    {
        return $this->audioFiles[0]['url'] ?? '';
    }

    /**
     * Whether verse timing data is available.
     *
     * @return  bool
     *
     * @since  1.2.0
     */
    public function hasVerseTiming(): bool
    {
        return !empty($this->verseTiming);
    }
}
