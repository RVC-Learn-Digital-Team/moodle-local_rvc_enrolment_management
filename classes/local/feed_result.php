<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_rvc_enrolment_management\local;

/**
 * What one feed said about one course.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feed_result {

    /** @var bool False when the feed could not be read; nothing may then be suspended. */
    public $available;

    /** @var int Feed rows found for the course. */
    public $rows;

    /** @var array Normalised person keys the feed lists for the course, as keys. */
    public $listed;

    /**
     * Constructor.
     *
     * @param bool $available
     * @param int $rows
     * @param array $listed normalised person keys as array keys
     */
    public function __construct(bool $available, int $rows = 0, array $listed = []) {
        $this->available = $available;
        $this->rows = $rows;
        $this->listed = $listed;
    }

    /**
     * Result for a feed that could not be read.
     *
     * @return self
     */
    public static function unavailable(): self {
        return new self(false);
    }

    /**
     * Whether the feed has any rows for the course.
     *
     * @return bool
     */
    public function course_in_feed(): bool {
        return $this->rows > 0;
    }

    /**
     * Whether the feed lists this person, ignoring capitals and surrounding spaces.
     *
     * @param string $value the person's local user field value
     * @return bool
     */
    public function lists(string $value): bool {
        return isset($this->listed[feed_reader::normalise($value)]);
    }
}
