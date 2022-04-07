<?php
/**
 * WordPress Plugin Administration API: WP_Plugin_Dependencies class
 *
 * @package WordPress
 * @subpackage Administration
 * @since 6.1.0
 */

/**
 * Core class for installing plugin dependencies.
 *
 * It is designed to add plugin dependencies as designated in the
 * `Requires Plugins` header to a new view in the plugins install page.
 */
class WP_Plugin_Dependencies {

	/**
	 * Holds 'get_plugins()'.
	 *
	 * @var array
	 */
	protected $plugins;

	/**
	 * Holds an array of sanitized plugin dependency slugs.
	 *
	 * @var array
	 */
	protected $slugs;

	/**
	 * Holds 'plugins_api()' data for plugin dependencies.
	 *
	 * @var array
	 */
	protected $plugin_data;

	/**
	 * Holds plugin filepath of plugins with dependencies.
	 *
	 * @var array
	 */
	protected $requires_plugins;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->requires_plugins = array();
		$this->plugin_data      = array();
	}

	/**
	 * Initialize, load filters, and get started.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_filter( 'plugins_api_result', array( $this, 'plugins_api_result' ), 10, 3 );
			add_filter( 'plugin_install_description', array( $this, 'plugin_install_description' ), 10, 2 );
			add_action( 'admin_init', array( $this, 'modify_plugin_row' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );

			// TODO: $this->get_dot_org_data() for core PR.
			add_action( 'plugins_loaded', array( $this, 'get_dot_org_data' ) );

			$required_headers = $this->parse_headers();
			$this->slugs      = $this->sanitize_required_headers( $required_headers );
			$this->deactivate_unmet_dependencies();
		}
	}

	/**
	 * Run get_plugins() and store result.
	 *
	 * @return array
	 */
	public function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->plugins = get_plugins();

		return $this->plugins;
	}

	/**
	 * Parse 'Requires Plugins' header.
	 * Store result with dependent plugin.
	 *
	 * @return \stdClass
	 */
	public function parse_headers() {
		$this->get_plugins();
		$required_headers = array();
		foreach ( array_keys( $this->plugins ) as $plugin ) {
			$requires_plugins = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, array( 'RequiresPlugins' => 'Requires Plugins' ) );
			if ( ! empty( $requires_plugins['RequiresPlugins'] ) ) {
				$required_headers[ $plugin ] = $requires_plugins;
				$sanitized_requires_slugs    = implode( ', ', $this->sanitize_required_headers( $required_headers ) );

				$this->requires_plugins[ $plugin ]['RequiresPlugins'] = $sanitized_requires_slugs;
			}
		}

		return $required_headers;
	}

	/**
	 * Sanitize headers.
	 *
	 * @param array $required_headers Array of required plugin headers.
	 * @return array
	 */
	public function sanitize_required_headers( $required_headers ) {
		$all_slugs = array();
		foreach ( $required_headers as $key => $headers ) {
			$sanitized_slugs = array();
			$exploded        = explode( ',', $headers['RequiresPlugins'] );
			foreach ( $exploded as $slug ) {
				$slug = trim( $slug );

				// Match to dot org slug format.
				if ( preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					$sanitized_slugs[] = $slug;
				}
			}
			$sanitized_slugs                          = array_unique( $sanitized_slugs );
			$this->plugins[ $key ]['RequiresPlugins'] = $sanitized_slugs;
			$all_slugs                                = array_merge( $all_slugs, $sanitized_slugs );
		}
		$all_slugs = array_unique( $all_slugs );
		sort( $all_slugs );

		return $all_slugs;
	}

	/**
	 * Deactivate plugins with unmet dependencies.
	 *
	 * @return void
	 */
	public function deactivate_unmet_dependencies() {
		$dependencies        = $this->get_dependency_filepaths();
		$deactivate_requires = array();

		foreach ( array_keys( $this->requires_plugins ) as $requires ) {
			if ( array_key_exists( $requires, $this->plugins ) ) {
				$plugin_dependencies = $this->plugins[ $requires ]['RequiresPlugins'];
				foreach ( $plugin_dependencies as $plugin_dependency ) {
					if ( is_plugin_active( $requires ) ) {
						if ( ! $dependencies[ $plugin_dependency ] || is_plugin_inactive( $dependencies[ $plugin_dependency ] ) ) {
							$deactivate_requires[] = $requires;
						}
					}
				}
			}
		}

		$deactivate_requires = array_unique( $deactivate_requires );
		deactivate_plugins( $deactivate_requires );
		set_site_transient( 'wp_plugin_dependencies_deactivate_plugins', $deactivate_requires, 10 );
	}

	/**
	 * Modify plugins_api() response.
	 *
	 * @param \stdClas  $res    Object of results.
	 * @param string    $action Variable for plugins_api().
	 * @param \stdClass $args   Object of plugins_api() args.
	 *
	 * @return \stdClass
	 */
	public function plugins_api_result( $res, $action, $args ) {
		if ( property_exists( $args, 'browse' ) && 'dependencies' === $args->browse ) {
			$res->info = array(
				'page'    => 1,
				'pages'   => 1,
				'results' => count( (array) $this->plugin_data ),
			);

			$res->plugins = $this->plugin_data;
		}

		return $res;
	}

	/**
	 * Get plugin data from WordPress API.
	 * Store result in $this->plugin_data.
	 */
	public function get_dot_org_data() {
		global $pagenow;
		$pages = array( 'plugin-install.php', 'plugins.php' );
		if ( ! in_array( $pagenow, $pages, true ) ) {
			return;
		}

		$this->plugin_data = (array) get_site_transient( 'wp_plugin_dependencies_plugin_data' );
		foreach ( $this->slugs as $key => $slug ) {
			// Don't hit plugins API if data exists.
			if ( array_key_exists( $slug, (array) $this->plugin_data ) ) {
				continue;
			}
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$args     = array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => true,
					'icons'             => true,
				),
			);
			$response = plugins_api( 'plugin_information', $args );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$this->plugin_data[ $response->slug ] = (array) $response;

			if ( ! in_array( $slug, $this->slugs, true ) ) {
				unset( $this->plugin_data[ $key ] );
			}
		}

		// Remove from $this->plugin_data if slug no longer a dependency.
		$differences = array_diff( array_keys( $this->plugin_data ), $this->slugs );
		if ( ! empty( $differences ) ) {
			foreach ( $differences as $difference ) {
				unset( $this->plugin_data[ $difference ] );
			}
		}

		ksort( $this->plugin_data );
		set_site_transient( 'wp_plugin_dependencies_plugin_data', $this->plugin_data, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Modify the plugin row.
	 *
	 * @return void
	 */
	public function modify_plugin_row() {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$dependency_paths = $this->get_dependency_filepaths();
		foreach ( $dependency_paths as $plugin_file ) {
			if ( $plugin_file ) {
				$this->modify_dependency_plugin_row( $plugin_file );
			}
		}
		foreach ( array_keys( $this->requires_plugins ) as $plugin_file ) {
			$this->modify_requires_plugin_row( $plugin_file );
		}
	}

	/**
	 * Actually make modifications to plugin row of plugin dependencies.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public function modify_dependency_plugin_row( $plugin_file ) {
		add_filter( 'network_admin_plugin_action_links_' . $plugin_file, array( $this, 'unset_action_links' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $plugin_file, array( $this, 'unset_action_links' ), 10, 2 );
		add_action( 'after_plugin_row_' . $plugin_file, array( $this, 'modify_plugin_row_elements' ), 10, 2 );
	}

	/**
	 * Actually make modifications to plugin row of requiring plugin.
	 *
	 * @param string $plugin_file Plugin file.
	 *
	 * @return void
	 */
	public function modify_requires_plugin_row( $plugin_file ) {
		add_action( 'after_plugin_row_' . $plugin_file, array( $this, 'modify_plugin_row_elements_requires' ), 10, 1 );
	}

	/**
	 * Modify the plugin row elements.
	 * Removes plugin row checkbox.
	 * Adds 'Required by: ...' information.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param array  $plugin_data Array of plugin data.
	 *
	 * @return void
	 */
	public function modify_plugin_row_elements( $plugin_file, $plugin_data ) {
		print '<script>';
		print 'jQuery("tr[data-plugin=\'' . esc_attr( $plugin_file ) . '\'] .plugin-version-author-uri").append("<br><br><strong>' . esc_html__( 'Required by:' ) . '</strong> ' . esc_html( $this->get_dependency_sources( $plugin_data ) ) . '");';
		print 'jQuery(".active[data-plugin=\'' . esc_attr( $plugin_file ) . '\'] .check-column input").remove();';
		print '</script>';
	}

	/**
	 * Modify the plugin row elements.
	 * Add `Requires: ...` information
	 *
	 * @param string $plugin_file Plugin file.
	 *
	 * @return void
	 */
	public function modify_plugin_row_elements_requires( $plugin_file ) {
		$this->plugin_data = get_site_transient( 'wp_plugin_dependencies_plugin_data' );

		// Exit if no plugin data found.
		if ( empty( $this->plugin_data ) ) {
			return;
		}

		$requires = $this->plugins[ $plugin_file ]['RequiresPlugins'];
		foreach ( $requires as $require ) {
			if ( isset( $this->plugin_data[ $require ] ) ) {
				$names[] = $this->plugin_data[ $require ]['name'];
			}
		}
		if ( ! empty( $names ) ) {
			$names = implode( ', ', $names );
			print '<script>';
			print 'jQuery("tr[data-plugin=\'' . esc_attr( $plugin_file ) . '\'] .plugin-version-author-uri").append("<br><br><strong>' . esc_html__( 'Requires:' ) . '</strong> ' . esc_html( $names ) . '");';
			print '</script>';
		}
	}

	/**
	 * Unset plugin action links so required plugins can't be removed or deactivated.
	 * Only when the requiring plugin is active.
	 *
	 * @param array  $actions     Action links.
	 * @param string $plugin_file Plugin file.
	 *
	 * @return array
	 */
	public function unset_action_links( $actions, $plugin_file ) {
		foreach ( $this->requires_plugins as $plugin => $requires ) {
			$dependents = explode( ',', $requires['RequiresPlugins'] );
			if ( is_plugin_active( $plugin ) && in_array( dirname( $plugin_file ), $dependents, true ) ) {
				if ( isset( $actions['delete'] ) ) {
					unset( $actions['delete'] );
				}
				if ( isset( $actions['deactivate'] ) ) {
					unset( $actions['deactivate'] );
				}
			}
		}

		return $actions;
	}

	/**
	 * Add 'Required by: ...' to plugin install dependencies view.
	 *
	 * @param string $description Short description of plugin.
	 * @param array  $plugin Array of plugin data.
	 *
	 * @return string
	 */
	public function plugin_install_description( $description, $plugin ) {
		$required = null;
		if ( in_array( $plugin['slug'], array_keys( $this->plugin_data ), true ) ) {
			$dependents  = $this->get_dependency_sources( $plugin );
			$required    = '<strong>' . __( 'Required by:' ) . '</strong> ' . $dependents;
			$description = $description . '<p>' . $required . '</p>';
		}

		return $description;
	}

	/**
	 * Display admin notice if dependencies not installed.
	 *
	 * @return void
	 */
	public function admin_notices() {
		// Plugin deactivated if dependencies not met.
		// Transient on a 10 second timeout.
		$deactivate_requires = get_site_transient( 'wp_plugin_dependencies_deactivate_plugins' );
		if ( ! empty( $deactivate_requires ) ) {
			foreach ( $deactivate_requires as $deactivated ) {
				$deactivated_plugins[] = $this->plugins[ $deactivated ]['Name'];
			}
			$deactivated_plugins = implode( ', ', $deactivated_plugins );
			printf(
				'<div class="notice-error notice is-dismissible"><p>'
				/* translators: 1: plugin names, 2: opening tag and link to Dependencies install page, 3: closing tag */
				. esc_html__( '%1$s plugin(s) could not be activated. There are uninstalled or inactive dependencies. Go to the %2$sDependencies%3$s install page.' )
				. '</p></div>',
				'<strong>' . esc_html( $deactivated_plugins ) . '</strong>',
				'<a href=' . esc_url_raw( admin_url( 'plugin-install.php?tab=dependencies' ) ) . '>',
				'</a>'
			);
		} else {
			// More dependencies to install.
			$installed_slugs = array_map( 'dirname', array_keys( $this->plugins ) );
			$intersect       = array_intersect( $this->slugs, $installed_slugs );
			asort( $intersect );
			if ( $intersect !== $this->slugs ) {
				printf(
					'<div class="notice-warning notice is-dismissible"><p>'
					/* translators: 1: opening tag and link to Dependencies install page, 2:closing tag */
					. esc_html__( 'There are additional plugins that must be installed. Go to the %1$sDependencies%2$s install page.' )
					. '</p></div>',
					'<a href=' . esc_url_raw( admin_url( 'plugin-install.php?tab=dependencies' ) ) . '>',
					'</a>'
				);
			}
		}
	}

	/**
	 * Get filepath of installed dependencies.
	 * If dependency is not installed filepath defaults to false.
	 *
	 * @return array
	 */
	private function get_dependency_filepaths() {
		$dependency_filepaths = array();
		foreach ( $this->slugs as $slug ) {
			foreach ( array_keys( $this->plugins ) as $plugin ) {
				if ( false !== strpos( $plugin, trailingslashit( $slug ) ) ) {
					$dependency_filepaths[ $slug ] = $plugin;
					break;
				} else {
					$dependency_filepaths[ $slug ] = false;
				}
			}
		}

		return $dependency_filepaths;
	}

	/**
	 * Get formatted string of dependent plugins.
	 *
	 * @param array $plugin_data Array of plugin data.
	 *
	 * @return string
	 */
	private function get_dependency_sources( $plugin_data ) {
		$sources = array();
		foreach ( $this->plugins as $plugin ) {
			if ( ! empty( $plugin['RequiresPlugins'] ) ) {
				foreach ( $plugin['RequiresPlugins'] as $dependent ) {
					if ( in_array( $plugin_data['slug'], $plugin['RequiresPlugins'], true ) ) {
						$sources[] = $plugin['Name'];
					}
				}
			}
		}
		$sources = array_unique( $sources );
		sort( $sources );
		$sources = implode( ', ', $sources );

		return $sources;
	}
}

( new WP_Plugin_Dependencies() )->init();
