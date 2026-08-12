<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_artqtml\local;

/**
 * Unit tests for list-page date filter parsing (security audit finding #6).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_list
 */
final class generation_list_date_filter_test extends \advanced_testcase {
    /**
     * Call a protected static method on generation_list.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call_protected(string $method, array $args) {
        $ref = new \ReflectionMethod(generation_list::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    /**
     * Valid Y-m-d becomes local midnight; junk and empty become null.
     */
    public function test_parse_filter_date_accepts_only_ymd(): void {
        $valid = $this->call_protected('parse_filter_date', ['2026-08-10']);
        $this->assertNotNull($valid);
        $expected = (new \DateTime('2026-08-10 00:00:00'))->getTimestamp();
        $this->assertSame($expected, $valid);

        $this->assertNull($this->call_protected('parse_filter_date', ['']));
        $this->assertNull($this->call_protected('parse_filter_date', ['   ']));
        $this->assertNull($this->call_protected('parse_filter_date', ['10/08/2026']));
        $this->assertNull($this->call_protected('parse_filter_date', ['tomorrow']));
        $this->assertNull($this->call_protected('parse_filter_date', ['2026-08-10<script>']));
        $this->assertNull($this->call_protected('parse_filter_date', ['2026-13-40']));
        $this->assertNull($this->call_protected('parse_filter_date', ['not-a-date']));
    }

    /**
     * Build_where binds timestamps only for strict Y-m-d; invalid dates add no clause.
     */
    public function test_build_where_date_bounds(): void {
        [$where, $params] = $this->call_protected('build_where', [
            5,
            true,
            '',
            '2026-08-10',
            '2026-08-12',
            0,
        ]);

        $this->assertStringContainsString('g.timecreated >= :datefrom', $where);
        $this->assertStringContainsString('g.timecreated <= :dateto', $where);
        $from = (new \DateTime('2026-08-10 00:00:00'))->getTimestamp();
        $to = (new \DateTime('2026-08-12 00:00:00'))->getTimestamp() + DAYSECS - 1;
        $this->assertSame($from, $params['datefrom']);
        $this->assertSame($to, $params['dateto']);

        [$wherebad, $paramsbad] = $this->call_protected('build_where', [
            5,
            true,
            '',
            'tomorrow',
            'also-bad',
            0,
        ]);
        $this->assertStringNotContainsString('datefrom', $wherebad);
        $this->assertStringNotContainsString('dateto', $wherebad);
        $this->assertArrayNotHasKey('datefrom', $paramsbad);
        $this->assertArrayNotHasKey('dateto', $paramsbad);
    }
}
