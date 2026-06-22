# Drupal Project

This directory is a local DDEV Drupal 11 project root.

Use the root README for bootstrap and run commands.

Core packages use the `^11.3` Composer constraint. This accepts current Drupal
11 minor and patch releases but will not cross the Drupal 12 major-version
boundary. To update the locked dependency set:

```bash
ddev composer update
ddev drush updatedb -y
ddev drush cache:rebuild
```
