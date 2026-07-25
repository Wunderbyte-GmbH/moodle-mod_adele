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

/**
 * Toggles the visibility of the host-course enrolment mode field on the
 * mod_adele settings form, based on the current participant-list selection.
 *
 * Moodle's declarative hideIf cannot express "hide unless value X or Y is
 * among the selected values" for a multi-select autocomplete, so the toggle
 * is driven from the live selection here instead.
 *
 * @module     mod_adele/hostenrolmentmode_visibility
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Read the currently selected values of the participantslist element.
const getSelectedValues = (select) => {
    return Array.from(select.options)
        .filter((option) => option.selected)
        .map((option) => option.value);
};

// Show or hide the host-course enrolment mode field group.
const setFieldVisible = (field, visible) => {
    if (!field) {
        return;
    }
    field.style.display = visible ? '' : 'none';
};

// Initialise the visibility toggle. triggerValues are the participant option
// values that reveal the host-course enrolment mode field.
export const init = (triggerValues) => {
    const participants = document.querySelector('[name="participantslist[]"]');
    const modeField = document.querySelector('#fitem_id_hostenrolmentmode');
    if (!participants || !modeField) {
        return;
    }

    const update = () => {
        const selected = getSelectedValues(participants);
        const shouldShow = selected.some((value) => triggerValues.includes(value));
        setFieldVisible(modeField, shouldShow);
    };

    participants.addEventListener('change', update);
    update();
};
