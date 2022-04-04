# Plugin Dependencies Tab

Plugin Name: Plugin Dependencies Tab
Plugin URI: https://github.com/afragen/plugin-dependencies-tab
Description: Parses 'Requires Plugins' header, add plugin install dependencies tab, and information about dependencies.
Author: Andy Fragen
License: MIT
Network: true
Requires at least: 5.2
Requires PHP: 5.6
Tested up to: 6.0
Stable tag: master

## Descripton

Parses a 'Requires Plugins' header and adds a Dependencies tab in the plugin install page. If a requiring plugin does not have all it's dependencies installed and active, it will not activate.

My solution to [#22316](https://core.trac.wordpress.org/ticket/22316). Feature plugin version of [PR #1724](https://github.com/WordPress/wordpress-develop/pull/1724)

* Parses the **Requires Plugins** header that defines plugin dependencies using a comma separated list of wp.org slugs.
* Adds a new view/tab to plugins install page ( **Plugins > Add New** ) titled **Dependencies** that contains plugin cards for all plugin dependencies.
* This view also lists which plugins require which plugin dependencies in the plugin card, though that feature requires the filter below to function. 😅
* In the plugins page, a dependent plugin is unable to be deleted or deactivated if the requiring plugin is active.
* Plugin dependencies can be deactivated or deleted if the requiring plugin is not active.
* Messaging in the plugin row description is inserted; as is data noting which plugins require the dependency.
* Displays a single admin notice with link to **Plugins > Add New > Dependencies** if not all plugin dependencies have been installed.
* Ensures that plugins with unmet dependencies cannot be activated.

## Need to add to core

Some of the messaging is too difficult to display without directly modifying core.

* Add filter hook after wp-admin/includes/class-wp-plugin-install-list-table.php:516
  * `$description = apply_filters( 'plugin_install_description', $description, $plugin );`

## Screenshots

1. Plugins page
2. Plugin Dependencies tab
