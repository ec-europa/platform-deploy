NextEuropa Workbench Moderation Fix
===================================
A Bug has been detected when updating sites from version 2.3 to 2.4 of the
platform.
It is related to the update of the "Workbench Moderation" module from
version 1.4 to 3.0.

The bug concerns any contents created with a published revision and a "draft"
one before the module upgrade.

It is described on the Drupal issue
https://www.drupal.org/project/workbench_moderation/issues/2958768:

"When a node (created prior to the Workbench Moderation 7.x-3.0 update) has a
published revision and a more-recent unpublished (e.g. Draft/Needs Review)
revision, any changes to the revision state of the more-recent revision changes
the published revision to unpublished, leaving no published revisions of the
node. Or just save the edited draft again and you will have the same behavior."

To fix this bug, we took the patch form this issue and adapted it into a drush
script in order to run it on each site independently. Indeed, the current
processes prevent us to run it through a platform "update" hook.

Usage
-----
To run this script please take these steps:
  - Backup the database of the site, before starting the update
  - Ensure the site run previously with the platform version 2.3
  - Ensure the site is deployed with a platform version 2.4
  - Ensure the Workbench Moderation "update" hook was run on version 2.4
  - Run the script "drush wmpu"

The operation will log the results to the console and to drupal's watchdog,
identified by workbench_moderation_patch

Running simulation
-----
To run the update simulation script please take these steps:
  - Backup the database of the site, before starting the update
  - Ensure the site run previously with the platform version 2.3
  - Ensure the site is deployed with a platform version 2.4
  - Ensure the Workbench Moderation "update" hook was run on version 2.4
  - Run the script "drush wms"

The operation will log the results to the console and to drupal's watchdog,
identified by workbench_moderation_patch, all messages will include at the start
of the line (Simulation run).

