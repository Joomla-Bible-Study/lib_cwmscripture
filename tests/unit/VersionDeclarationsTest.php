<?php

/**
 * The version is declared in three places and only two of them update themselves.
 *
 * `cwm-bump` rewrites the manifest, and its VersionTracker syncs `package.json`
 * (and `versions.json` where a project has one). `LibraryVersion::VERSION` is a
 * hardcoded constant that nothing writes, so it drifts silently — it sat one
 * release behind at 1.1.3 while the manifest said 1.1.4, and `package.json` had
 * never moved off 1.0.0. See issue #15.
 *
 * That constant is the one that hurts: it is the value behind
 * `LibraryVersion::satisfies()` and `needsUpgrade()`, so a consumer gating on a
 * minimum library version gets an answer from a stale number. A downstream
 * extension requiring 1.1.6 would be told the site has 1.1.5 and refuse to
 * install against a library that was in fact new enough.
 *
 * This does not prevent the drift. It stops it shipping: the mismatch becomes a
 * failing test at release prep instead of a wrong answer on someone else's site.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests;

use PHPUnit\Framework\TestCase;

class VersionDeclarationsTest extends TestCase
{
    /**
     * Repository root.
     */
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The manifest is the source of truth — it is what `cwm-bump` writes and what
     * Joomla records in `#__extensions`.
     */
    private static function manifestVersion(): string
    {
        $xml = simplexml_load_file(self::root() . '/cwmscripture.xml');

        return trim((string) $xml->version);
    }

    public function testLibraryVersionConstantMatchesTheManifest(): void
    {
        // Read the constant out of the source rather than loading the class: it
        // needs _JEXEC and Joomla's autoloader, and the value is a literal.
        $source = (string) file_get_contents(self::root() . '/src/LibraryVersion.php');

        $this->assertSame(
            1,
            preg_match("/public const VERSION\s*=\s*'([^']+)'/", $source, $m),
            'Could not find the VERSION constant — has it been renamed or made non-literal?'
        );

        $this->assertSame(
            self::manifestVersion(),
            $m[1],
            'LibraryVersion::VERSION has drifted from the manifest. Nothing in the release '
            . 'tooling writes it (issue #15), so it has to be bumped by hand in the same commit '
            . 'as the manifest. Consumers gate on it through satisfies()/needsUpgrade(), so a '
            . 'stale value makes them refuse a library that is new enough.'
        );
    }

    public function testPackageJsonMatchesTheManifest(): void
    {
        $package = json_decode((string) file_get_contents(self::root() . '/package.json'), true);

        $this->assertIsArray($package);

        // cwm-bump's VersionTracker writes this one, so a mismatch means the bump
        // was not run (a hand-edited manifest) rather than a forgotten edit.
        $this->assertSame(
            self::manifestVersion(),
            $package['version'] ?? null,
            'package.json has drifted from the manifest. cwm-bump syncs it, so this usually '
            . 'means the manifest was edited by hand instead of through `composer bump-version`.'
        );
    }
}
