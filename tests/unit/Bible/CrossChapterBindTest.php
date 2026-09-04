<?php

/**
 * A passage spanning chapters threw instead of returning verses.
 *
 * `DatabaseQuery::bind()` takes its `$value` parameter by reference, and the
 * cross-chapter branch handed it `$parsed['verse_begin'] ?: 1` — an expression,
 * not a variable. PHP cannot reject that at compile time for a dynamic method
 * call, so it raised an `Error` at runtime on the first cross-chapter reference
 * (`Genesis 1:26-2:3`). `AbstractBibleProvider` catches `\Exception`, not
 * `\Error`, so it escaped to the page.
 *
 * ⚠️ StructuredResolutionTest overrides `queryVerses()` with a stub, so the real
 * query was never executed and the suite stayed green. This drives the real
 * method instead: the fake query below declares `bind(&$value)` exactly as
 * Joomla does, which is the whole mechanism — so it reproduces the failure
 * without needing a database.
 *
 * @package    Lib_cwmscripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @since  1.1.21
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Bible\Provider\LocalProvider;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.1.21
 */
class CrossChapterBindTest extends TestCase
{
    /**
     * @return  void
     * @since 1.1.21
     */
    #[TestDox('⚠️ A cross-chapter passage binds without passing an expression by reference')]
    public function testCrossChapterQueryDoesNotThrow(): void
    {
        $result = $this->runQuery([
            'book'          => 1,
            'chapter_begin' => 1,
            'chapter_end'   => 2,
            // 0 is what makes the old code reach for its `?: 1` default, which
            // is precisely the expression it then tried to bind.
            'verse_begin'   => 0,
            'verse_end'     => 0,
        ]);

        $this->assertInstanceOf(BiblePassageResult::class, $result);
    }

    /**
     * The single-chapter branch binds plain array elements and was never
     * affected; asserting it keeps the fix honest about its scope.
     *
     * @return  void
     * @since 1.1.21
     */
    #[TestDox('A single-chapter passage still queries cleanly')]
    public function testSingleChapterQueryDoesNotThrow(): void
    {
        $result = $this->runQuery([
            'book'          => 1,
            'chapter_begin' => 1,
            'chapter_end'   => 1,
            'verse_begin'   => 1,
            'verse_end'     => 3,
        ]);

        $this->assertInstanceOf(BiblePassageResult::class, $result);
    }

    /**
     * Drive the real queryVerses() against a query object that reproduces
     * Joomla's by-reference bind() signature.
     *
     * @param   array<string, int>  $parsed  Parsed reference parts.
     *
     * @return  BiblePassageResult
     * @since 1.1.21
     */
    private function runQuery(array $parsed): BiblePassageResult
    {
        // A stand-in for the query builder whose bind() declares $value BY
        // REFERENCE, exactly as Joomla's does. That signature is the entire
        // mechanism of the bug, so reproducing it needs no database.
        $db = new class () implements DatabaseInterface {
            /** @var array<string, mixed> */
            public array $bound = [];

            public function getQuery($new = false)
            {
                return new class ($this) {
                    public function __construct(private $db)
                    {
                    }

                    public function bind($name, &$value, $type = null): self
                    {
                        $this->db->bound[$name] = $value;

                        return $this;
                    }

                    public function __call($name, $args): self
                    {
                        return $this;
                    }
                };
            }

            public function quoteName($name, $as = null)
            {
                return \is_array($name) ? implode(',', $name) : (string) $name;
            }

            public function setQuery($query, $offset = 0, $limit = 0)
            {
                return $this;
            }

            public function loadObjectList($key = '', $class = 'stdClass')
            {
                return [];
            }
        };

        $provider = new class () extends LocalProvider {
            public ?DatabaseInterface $fakeDb = null;

            protected function getDatabase(): DatabaseInterface
            {
                return $this->fakeDb;
            }

            /**
             * @param   array<string, int>  $parsed       Parsed reference.
             * @param   string              $reference    Human reference.
             * @param   string              $translation  Translation key.
             *
             * @return  BiblePassageResult
             */
            public function callQueryVerses(array $parsed, string $reference, string $translation): BiblePassageResult
            {
                return $this->queryVerses($parsed, $reference, $translation);
            }
        };

        $provider->fakeDb = $db;

        return $provider->callQueryVerses($parsed, 'Genesis 1:26-2:3', 'kjv');
    }
}
