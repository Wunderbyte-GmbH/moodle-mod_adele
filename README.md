Adaptive e-Learning Paths activity (moodle-mod_adele)
================

[![Moodle Plugin CI](https://github.com/Wunderbyte-GmbH/moodle-mod_adele/actions/workflows/moodle-plugin-ci.yml/badge.svg)](https://github.com/Wunderbyte-GmbH/moodle-mod_adele/actions?query=workflow%3A%22Moodle+Plugin+CI%22)

The AdeLe activity is the in-course entry point of a learning path: it embeds a path into an ordinary course, shows it to teachers and learners, and decides which of that course's participants the path applies to.

AdeLe is not a single plugin but a set of three that work as one system. They are developed together and declare each other as dependencies, so they can only be installed and updated as a set.

* **local_adele** is the learning path itself: the graphical editor, the node structure, the completion and restriction logic, and the Vue 3 frontend.
* **mod_adele** is the in-course entry point: it embeds a learning path in an ordinary course and decides which of that course's participants the path applies to.
* **enrol_adele** is the enrolment layer: it turns the learning path state into actual course enrolments and role assignments, and reconciles them.

This README documents **mod_adele** - the second bullet point above. The other two plugins are documented in their own repositories.

Because the responsibilities are split this way, no rule exists twice: the learning path is defined in local_adele, the decision who it applies to is made here, and every enrolment that follows is owned by enrol_adele. That is also why a partial installation does not work - a missing sibling means a missing part of the mechanism.


Requirements
------------

This plugin requires Moodle 4.5+

It also requires the other AdeLe plugins. All three are developed together and must be installed in matching versions:

* **local_adele (AdeLe learning paths)** - required dependency, declared in version.php\
  https://github.com/Wunderbyte-GmbH/moodle_local_adele
* **enrol_adele (AdeLe enrolment)** - required dependency, declared in version.php\
  https://github.com/Wunderbyte-GmbH/moodle-enrol_adele


Motivation for this plugin
--------------------------

A learning path is a site-wide object, but learners meet it inside a course. This activity is that meeting point, and it is where the one question a path cannot answer itself is decided: *who* does this path apply to.

Keeping that decision in the activity rather than in the path means the same path can serve several audiences - a whole course in one place, only the participants of its starting courses in another - without duplicating the path.


Installation
------------

Install the plugin like any other plugin to folder
/mod/adele

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

Add the activity to a course and select an existing learning path. The activity form controls:

* **Display mode** - the path is shown on the course page itself, or as a separate activity.
* **Result visibility** - everyone sees an overview of all subscribed participants, or everyone sees only their own results.
* **Participants** - which participants of this course the path applies to. Three options, combinable:
  * everyone enrolled in this course,
  * everyone enrolled in at least one starting node course of the path,
  * everyone enrolled in any node course of the path.
* **Host course enrolment mode** - whether the resulting enrolment in this course is *visible* (granted), *hidden* (recorded but no access), or *none*.
* **Completion** - optionally mark the activity complete once the learning path is finished.

This plugin has no site-wide settings of its own; the learning paths it embeds are configured in local_adele.


Capabilities
------------

This plugin introduces these additional capabilities:

* **mod/adele:addinstance** - add a new AdeLe activity to a course.
* **mod/adele:readinstance** - view an AdeLe activity.


Scheduled Tasks
---------------

This plugin does not introduce any additional scheduled tasks. Saving or deleting an activity queues an ad-hoc repair in enrol_adele, so a settings change takes effect within one cron run instead of waiting for that plugin's nightly reconciliation.


How this plugin works / Pitfalls
--------------------------------

The activity holds three enrolment-relevant settings: the learning path, the participant options, and the host course enrolment mode. Together they answer "should this user have access to this course because of this path", and that question is answered in exactly one place - `mod_adele\local\host_policy`. Both the live event path and enrol_adele's nightly reconciliation call it, so they cannot disagree.

**Pitfall:** several activities may embed the same learning path in the same course. They share one enrolment instance, and the most generous setting wins - an activity set to *none* does not override a sibling set to *visible*.

**Pitfall:** changing the learning path of an existing activity is a two-sided operation. The new path has to be established and the old one taken away, and no enrolment event fires for either, because nobody's course membership changes. The activity therefore reconciles the previous learning path as well, and enrol_adele's sweep catches whatever is left.

**Pitfall:** a subscription is created when a user is enrolled into the course that **hosts** the activity, not into a node course. Users entitled through a node course get their access from the participant options instead.


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/Wunderbyte-GmbH/moodle-mod_adele


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/Wunderbyte-GmbH/moodle-mod_adele/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/Wunderbyte-GmbH/moodle-mod_adele/issues

Please create pull requests on Github:
https://github.com/Wunderbyte-GmbH/moodle-mod_adele/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Maintainers
-----------

The plugin is maintained by\
Wunderbyte GmbH

Copyright
---------

The copyright of this plugin is held by\
Wunderbyte GmbH

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
