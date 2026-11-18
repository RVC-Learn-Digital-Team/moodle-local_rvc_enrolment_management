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
 * Filters for the people lists on the course page: method, role, account and person.
 *
 * Rows carry the person's name fields, username, email, usersuspended and userdeleted.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class people_filter {
    /** @var string[] Filter names, as used in page parameters. */
    const NAMES = ['method', 'role', 'account', 'person'];

    /** @var string[] Account states, in the order the filter offers them. */
    const ACCOUNTS = ['active', 'suspended', 'deleted'];

    /**
     * The state of the person's Moodle account.
     *
     * @param \stdClass $row
     * @return string one of self::ACCOUNTS
     */
    public static function account(\stdClass $row): string {
        if (!empty($row->userdeleted)) {
            return 'deleted';
        }
        return empty($row->usersuspended) ? 'active' : 'suspended';
    }

    /**
     * Whether a row passes the filters. Empty filters pass everything.
     *
     * The person filter is split into words; every word must appear, ignoring capitals, in the
     * person's full name, email or username.
     *
     * @param string $method the row's enrol method
     * @param \stdClass $row
     * @param string $roles the person's role names in the course
     * @param array $filters method, role, account and person, as strings
     * @return bool
     */
    public static function matches(string $method, \stdClass $row, string $roles, array $filters): bool {
        if (($filters['method'] ?? '') !== '' && $filters['method'] !== $method) {
            return false;
        }
        if (($filters['role'] ?? '') !== '' && $filters['role'] !== $roles) {
            return false;
        }
        if (($filters['account'] ?? '') !== '' && $filters['account'] !== self::account($row)) {
            return false;
        }
        $haystack = \core_text::strtolower(fullname($row) . ' ' . ($row->email ?? '') . ' ' . ($row->username ?? ''));
        foreach (preg_split('/\s+/', trim((string) ($filters['person'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (strpos($haystack, \core_text::strtolower($word)) === false) {
                return false;
            }
        }
        return true;
    }
}
