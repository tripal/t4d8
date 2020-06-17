[![Build Status](hhttps://travis-ci.org/tripal/t4d8.svg?branch=9.x-4.x)](https://travis-ci.org/tripal/t4d8)


![alt tag](https://raw.githubusercontent.com/tripal/tripal/7.x-3.x/tripal/theme/images/tripal_logo.png)

# Tripal 4 Drupal 9

## UNDER ACTIVE DEVELOPMENT

This project acts as the home of Tripal 4 development. Once Tripal 4 is stable, it will be merged back into the [Core Tripal Repository](https://github.com/tripal/tripal).

**All PRs should be made against branch 9.x-4.x which is compatible with Drupal 8.8.x, 8.9.x and 9.0.x**

## Required Dependencies
* Drupal:
  * Drupal 8.8.x or 9.0.x
  * Drupal core modules: Search, Path, View, Entity, and PHP modules.
* PostgreSQL
* PHP 7.3+
* UNIX/Linux

## Traditional Installation

1. Install [Drupal](https://www.drupal.org/docs/develop/using-composer/using-composer-to-install-drupal-and-manage-dependencies).
2. Clone this repository in your `web/modules` directory.
3. Enable Tripal in your site using the Administration Toolbar > Extend
4. Use drush to rebuild the cache (`drush cache-rebuild`) so Tripal menu items appear correctly.

## Docker (DEVELOPMENT ONLY)

Docker is the fastest way to get started with Tripal 4 development. Production containers are not available yet.  

### Docker Setup
- Copy tripaldocker/dev/.env.example to tripaldocker/dev/.env
- Run `docker-compose up -d`
- Next start the database using `docker-compose drupal service postgresql start`
- Visit localhost:9000/drupal9/web
- The Drupal site will already be installed and Tripal + Tripal Chado will be enabled.

Since volumes are automatically setup for you, your data will be persisted thereafter (even when running docker-compose down). To remove data permanently, run `docker-compose rm`.

### Docker CLI
- To access the drupal container run:
  - `docker-compose exec drupal bash`
- To access the database using psql run:
  - `docker-compose exec drupal psql -q --dbname=drupal9_dev --host=localhost --port=5432 --username=drupaladmin`
  - The password is `drupal9developmentonlylocal`
- To run drush commands:
  - `docker-compose exec drupal drupal9/vendor/bin/drush [YOUR OPTIONS]`
- To run unit tests:
  - `docker-compose exec drupal drupal9/vendor/bin/phpunit --config drupal9/web/core drupal9/web/modules/t4d8`

## Development Testing

See the [Drupal "Running PHPUnit tests" guide](https://www.drupal.org/node/2116263) for instructions on running tests on your local environment. In order to ensure our Tripal functional testing is fully bootstrapped, tests should be run from Drupal core.

If you are using the docker distributed with this module, then you can run tests using:
```
docker-compose exec drupal drupal9/vendor/bin/phpunit --config drupal9/web/core drupal9/web/modules/t4d8
```

## Documentation

[Documentation for Tripal 4 has begun on ReadtheDocs](https://tripal4.readthedocs.io/en/latest/dev_guide.html). **Please keep in mind the URL for this documentation will change once Tripal 4 is released.**

# Upgrade Progress

## Currently working on [Group 1](https://github.com/tripal/t4d8/issues/1) and [Group 2](https://github.com/tripal/t4d8/issues/2)

We currently have working entities for the following: Tripal vocabularies, Tripal Terms, Tripal Content Types, Tripal Content! However, nothing is connected to Chado at this point (to ensure it is chado-agnostic).

**We are currently focused on updating the Tripal and Tripal Chado API to help extension module developers begin their module upgrades.**

### How to get involved!

This upgrade to Drupal 8 is a community effort. As such, we NEED YOUR HELP! In order to make it less overwhelming for you to jump in and help, the PMC (project-management-committee) has created issues tagged `good first issue`. To take one on, just comment with your intent! We're also in the process of adding documentation through RTD to help orient new developers. Please comment on the issue [How can we help you?](https://github.com/tripal/t4d8/issues/16) with any ideas for documentation you would find useful and any tips which helped you get started!

### Our upgrade Process (in detail)

Tripal 4 development has been planned in the issue queue of this repository with the entire code-based of Tripal 3 being catagorized into groups which should be completed in order. For a summary of the tasks assigned to a given group, go to the issue labelled with the `roadmap` and group tag for a specific group. For example, for Group 1, the task list is in #1 which has both the `Roadmap` and `Group 1` tags.

To aid in the development of Tripal 4,
1. Choose a task from the current group
2. Comment on an issue stating your intention
3. Keep track of your progress and design in this issue
4. Once the task is complete, create a PR referencing this issue.
5. Once the PR is merged, check the task checkbox in the original `Roadmap` issue.
