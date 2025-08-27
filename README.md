# Shortlink Manager

## Introduction

The Shortlink Manager module provides a powerful and automated solution for creating, managing, and redirecting short URLs within Drupal. It simplifies the process of creating trackable shortlinks for content on your site, allowing for automated UTM tagging and custom redirects.

This module is designed for developers and site builders who need a robust, flexible, and integrated way to handle shortlinks without relying on external services.

## Features

* **Content Entity-based Shortlinks:** Uses a custom content entity to store shortlink data, providing full integration with Drupal's content management ecosystem.

* **Automated Generation:** Automatically creates shortlinks for new content based on configurable rules for content types and bundles.

* **UTM Integration:** Connects with the UTM Sets feature to automatically apply predefined UTM parameters to destination URLs.

* **Customizable Redirects:** Provides configurable redirect statuses (301, 307, 308) and the ability to set custom destination URLs.

* **Unique Path Generation:** Guarantees unique, conflict-free shortlink paths using a dedicated service.

* **Extendable Architecture:** Built with Drupal's core principles of services, entities, and controllers, making it easy to extend and customize.

## Requirements

This module requires **Drupal core 10** or higher. It is a companion to the **UTM Sets** module, which provides the `utm_set` entity.

## Installation

1.  **Add the repository to Composer.**

    Since this module is not hosted on Drupal.org, you will need to add the repository to your project's `composer.json` file. Add the following to the `repositories` section:

    ```json
    "repositories": [
        {
            "type": "git",
            "url": "[https://github.com/r0nn1ef/shortlink_manager.git](https://github.com/r0nn1ef/shortlink_manager.git)"
        }
    ]
    ```

2.  **Require the module.**

    After adding the repository, you can require the module using the standard Composer command:

    ```bash
    composer require your-github-username/shortlink_manager:^1.0
    ```

3.  **Enable the module.**

    Finally, enable the module via the Drupal user interface (`Extend` > `Shortlink Manager`) or using Drush:
    ```bash
    drush en shortlink_manager
    ```

4.  Ensure the companion **UTM Sets** module is also installed and enabled.

## Configuration

The module's main configuration form is located at `/admin/config/content/shortlink-settings`.

### Main Settings

* **Path Prefix:** Configure the path prefix for all shortlinks (e.g., `go`). This is the part of the URL that comes before the unique identifier (e.g., `yoursite.com/go/abc`).

* **Redirect Status:** Set the default HTTP status for all shortlink redirects (e.g., 301, 307, 308).

### Auto-Generation

Navigate to `/admin/config/content/shortlink-auto-generate` to configure which content types and bundles should automatically generate shortlinks upon creation. You can also specify a default UTM Set to be applied.

## Usage

Once configured, the module will automatically create shortlinks when new content is saved.

* **Accessing Shortlinks:** You can find the shortlinks you've created by navigating to `/admin/content/shortlinks`.

* **Custom Redirects:** When creating or editing a shortlink, you can specify a `destination_override` to point the shortlink to a custom URL, including external sites.

## Database Considerations

The `shortlink` content entity stores a record for every shortlink created. For sites with a large volume of automatically generated shortlinks, this will increase the database size. Be sure to consider your database's capacity.

## Maintainers

* **John Doe** (john.doe@example.com) - Initial creator and maintainer.

Feel free to open an issue or pull request if you find a bug or have a suggestion for a new feature.
