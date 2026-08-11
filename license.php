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

/**
 * Licensz fül (functional spec ch.10, Admin oldal): upload a signed .lic file, view current
 * license status, and configure the expiry/usage warning thresholds (Lic-007).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_artqtml\form\license_form;
use local_artqtml\local\license_checker;
use local_artqtml\local\text_extractor;

admin_externalpage_setup('local_artqtml_license');

// Export the encrypted integrity report (a .enc file for the vendor to decrypt - see
// tools/decrypt_integrity_log.php). Security: this never exposes the modified/missing file
// names/paths itself, only what license_checker already stores encrypted; must run before any
// other output on this page, since a file download needs to set its own headers.
if (optional_param('exportintegrity', 0, PARAM_BOOL)) {
    require_sesskey();

    $export = license_checker::export_integrity_report();
    if ($export === null) {
        \core\notification::error(get_string('errorlicensenointegrityreport', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/license.php'));
    }

    $filename = 'artqtml-integrity-' . $export['error_code'] . '.enc';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($export['content']));
    echo $export['content'];
    exit;
}

// Lic-007: admin-configurable warning thresholds.
if (optional_param('submitthresholds', 0, PARAM_BOOL)) {
    require_sesskey();

    $annualdays = optional_param('licenseannualwarningdays', 30, PARAM_INT);
    $questionpct = optional_param('licensequestionwarningpct', 80, PARAM_INT);

    set_config('licenseannualwarningdays', max(1, $annualdays), 'local_artqtml');
    set_config('licensequestionwarningpct', max(1, min(99, $questionpct)), 'local_artqtml');

    \core\notification::success(get_string('licensethresholdssaved', 'local_artqtml'));
    redirect(new moodle_url('/local/artqtml/license.php'));
}

$mform = new license_form();

if ($data = $mform->get_data()) {
    $draftitemid = (int) ($data->licensefile ?? 0);
    $files = text_extractor::draft_files($draftitemid);
    $file = reset($files);

    if ($file === false) {
        \core\notification::error(get_string('errorlicensenofile', 'local_artqtml'));
    } else if (strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) !== 'lic') {
        // M-01: the filepicker's accepted_types=['.lic'] is a client-side hint only - a
        // differently-named file could still land in the draft area via another repository
        // (e.g. "Server files", a URL download repo), so this must also be checked server-side.
        \core\notification::error(get_string('errorlicensewrongextension', 'local_artqtml'));
    } else {
        $result = license_checker::upload($file->get_content());
        if ($result['success']) {
            \core\notification::success(get_string('licenseuploadsuccess', 'local_artqtml'));
        } else {
            \core\notification::error($result['error']);
        }
    }

    redirect(new moodle_url('/local/artqtml/license.php'));
}

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();

echo local_artqtml_license_warning_banner();
echo local_artqtml_token_warning_banner();
echo local_artqtml_draftcourse_warning_banner();

echo $OUTPUT->heading(get_string('licensestatusheading', 'local_artqtml'), 3);
echo license_checker::render_status_panel();

$integritypanel = license_checker::render_file_integrity_panel();
if ($integritypanel !== '') {
    echo $OUTPUT->heading(get_string('licensefileintegrityheading', 'local_artqtml'), 3);
    echo $integritypanel;
}

echo $OUTPUT->heading(get_string('licenseuploadheading', 'local_artqtml'), 3);
$mform->display();

echo $OUTPUT->heading(get_string('licensethresholdsheading', 'local_artqtml'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/artqtml/license.php'))->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submitthresholds', 'value' => 1]);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('licenseannualwarningdays', 'local_artqtml'), ['for' => 'id_licenseannualwarningdays']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'id' => 'id_licenseannualwarningdays', 'name' => 'licenseannualwarningdays',
    'value' => (int) (get_config('local_artqtml', 'licenseannualwarningdays') ?: 30),
    'min' => 1, 'class' => 'form-control w-auto',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag(
    'label',
    get_string('licensequestionwarningpct', 'local_artqtml'),
    ['for' => 'id_licensequestionwarningpct']
);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'id' => 'id_licensequestionwarningpct', 'name' => 'licensequestionwarningpct',
    'value' => (int) (get_config('local_artqtml', 'licensequestionwarningpct') ?: 80),
    'min' => 1, 'max' => 99, 'class' => 'form-control w-auto',
]);
echo html_writer::end_div();

echo html_writer::tag('button', get_string('savechanges'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
