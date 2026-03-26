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
 * Interface for Bible providers that can return audio URLs.
 *
 * Providers implementing this interface can supply audio stream URLs
 * for scripture passages, enabling audio Bible playback in frontends.
 *
 * @since  1.2.0
 */
interface AudioProviderInterface
{
    /**
     * Get audio URLs for a scripture chapter.
     *
     * Returns an array of audio file URLs for the given book and chapter.
     * Each element contains at minimum a 'url' key with the stream URL.
     * Providers may include additional metadata such as verse timing data.
     *
     * @param   string  $book         USFM book code (e.g. "GEN", "MAT", "REV")
     * @param   int     $chapter      Chapter number
     * @param   string  $translation  Translation/fileset identifier
     *
     * @return  AudioPassageResult  Audio result with URLs and metadata
     *
     * @since  1.2.0
     */
    public function getAudio(string $book, int $chapter, string $translation): AudioPassageResult;

    /**
     * Whether this provider supports verse-level timing data.
     *
     * When true, audio results may include per-verse timestamps that
     * enable synchronized text highlighting during playback.
     *
     * @return  bool
     *
     * @since  1.2.0
     */
    public function supportsVerseTiming(): bool;

    /**
     * Get available audio translations/filesets.
     *
     * @return  array  Array of ['id' => string, 'name' => string, 'language' => string, 'type' => string]
     *                 where type is 'audio' or 'audio_drama'
     *
     * @since  1.2.0
     */
    public function getAvailableAudioTranslations(): array;
}
