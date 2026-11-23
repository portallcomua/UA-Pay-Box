<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Оновлення плагіна з GitHub Releases.
 * Реліз має мати тег vX.Y.Z і (бажано) прикріплений ZIP «<slug>.zip».
 */
class UPB_Updater {

	private $file;
	private $basename;
	private $slug;
	private $repo;
	private $version;

	public function __construct( $file, $repo, $version ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->repo     = $repo;
		$this->version  = $version;
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_dir' ), 10, 4 );
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
		add_action( 'admin_post_upb_check_github_updates', array( $this, 'force_check' ) );
	}


	/** Add a one-click forced update check to the plugin row. */
	public function action_links( $links ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=upb_check_github_updates' ),
			'upb_check_github_updates'
		);
		$links[] = '<a href="' . esc_url( $url ) . '">Перевірити оновлення</a>';
		return $links;
	}

	/** Clear the six-hour GitHub cache and ask WordPress to check immediately. */
	public function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'upb_check_github_updates' );
		delete_transient( 'upb_gh_' . md5( $this->repo ) );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php?upb_github_checked=1' ) );
		exit;
	}

	private function remote() {
		$key    = 'upb_gh_' . md5( $this->repo );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . $this->slug ) )
		);
		$out = null;
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$d = json_decode( wp_remote_retrieve_body( $resp ) );
			if ( $d && isset( $d->tag_name ) ) {
				$zip = isset( $d->zipball_url ) ? $d->zipball_url : '';
				if ( ! empty( $d->assets ) ) {
					foreach ( $d->assets as $a ) {
						if ( isset( $a->name, $a->browser_download_url ) && preg_match( '/\.zip$/i', $a->name ) ) {
							$zip = $a->browser_download_url;
							if ( $a->name === $this->slug . '.zip' ) {
								break;
							}
						}
					}
				}
				$out = (object) array( 'version' => ltrim( $d->tag_name, 'vV' ), 'zip' => $zip, 'url' => $d->html_url );
			}
		}
		set_transient( $key, $out ? $out : 0, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	public function check( $t ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}
		$r = $this->remote();
		if ( $r && $r->zip && version_compare( $this->version, $r->version, '<' ) ) {
			$t->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $r->version,
				'package'     => $r->zip,
				'url'         => $r->url,
			);
		}
		return $t;
	}

	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $res;
		}
		$r = $this->remote();
		if ( ! $r ) {
			return $res;
		}
		return (object) array(
			'name'          => 'Universal Pay Box PRO',
			'slug'          => $this->slug,
			'version'       => $r->version,
			'download_link' => $r->zip,
			'sections'      => array( 'description' => 'Автономна монетизація для WordPress.' ),
		);
	}

	/** GitHub zipball розпаковується в папку з хешем — повертаємо правильну назву папки. */
	public function fix_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		global $wp_filesystem;
		$new = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $new ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $new, true ) ) {
			return $new;
		}
		return $source;
	}
}
