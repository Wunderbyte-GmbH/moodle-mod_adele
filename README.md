# AdeLe - Adaptive eLearning Paths (frontend plugin) #

Adele is a Moodle activity module that embeds and exposes **learning paths** managed by the companion plugin **local_adele** inside a course.

This module lets teachers control how the learning path is displayed, decide who gets subscribed, view the student's progresses and optionally mark the activity complete once the learning path is finished. For using the adele functionality in moodle, you also need to install **local_adele** as the back end plugin.

## Key features ##

- adds a Moodle activity for selecting an existing Adele learning path
- renders the learning path inside the course for teachers and learners
- provides two display modes:
  - on the course level, or
  - as a activity
- provides two visibility/result-list modes:
  - everyone sees the overview of all subscribed participants
  - everyone sees only their own results
- supports different participant subscription strategies:
  - everyone enrolled in the current course
  - everyone enrolled in at least one configured starting-node course
- automatically subscribes users to the selected learning path when the activity is created/updated
- supports activity completion based on learning-path completion

## Requirements ##

### Moodle ###

- Moodle 4.1 to 4.5 are supported.

### Required plugin dependency ###

- `local_adele` is required.
- Minimum required version: `2024101500`

## Installing via uploaded ZIP file ##

1. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually ##

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/mod/adele

Afterwards, log in to your Moodle site as an admin and go to _Site administration >
Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## License ##

2026 Wunderbyte GmbH <info@wunderbyte.at>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program.  If not, see <https://www.gnu.org/licenses/>.
