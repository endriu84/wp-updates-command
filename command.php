<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class Updates_Command {

	public $alias = '';

	public $file = '';

	public $dry_run = '';

	public $updates = array();

	const CMD_OPT_JSON = [ 'return' => true, 'launch' => true, 'exit_error' => true, 'parse' => 'json' ];

	const CMD_OPT = [ 'return' => true, 'launch' => true, 'exit_error' => true, 'parse' => false ];


	private function setup( $args, $assoc_args ) {

		// alias
		if ( isset( $assoc_args['alias'] ) ) {
			if ( isset( WP_CLI::get_runner()->aliases[ $assoc_args['alias'] ] ) ) {
				$this->alias = $assoc_args['alias'];
			}
		}

		// dry-run
		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->dry_run = '--dry-run';
		}

		// file
		if ( isset( $assoc_args['file'] ) ) {

			if ( filter_var( $assoc_args['file'], FILTER_VALIDATE_URL ) ) {
				$this->file = filter_var( $assoc_args['file'], FILTER_SANITIZE_URL );
			} else {
				$dirname = dirname( ABSPATH . $assoc_args['file'] );
				if ( ! file_exists( $dirname ) ) {
					mkdir( $dirname );
				}
				$this->file = $dirname . '/' . basename( $assoc_args['file'] );

				if ( ! file_exists( $this->file ) ) {
					touch( $this->file );
					$this->updates = [
						'core' => [],
						'plugin' => [],
						'theme' => [],
						'translation' => []
					];

				} else {

					$this->updates = json_decode( file_get_contents( $this->file ), true );
				}
			}
		}

		$this->session = $this->current_session();

		$this->date = date_i18n( 'd-m-Y H:i' );
	}

	/**
     * Run all available updates (core / plugins / theme / db / translation) and save raport to the file
     *
     * ## OPTIONS
     *
     * [--alias=<text>]
     * : Run command against the remote server ( alias listed in wp-cli.yml )
     *
	 * [--file=<text>]
	 * : Path to the json file, where report will be saved
	 * ---
     * default: updates.json
	 *
	 * [--dry-run]
	 * : dry run option
	 *
     * ## EXAMPLES
     *
     *     wp update run --alias=@prod --file=raport.json
     *
     * @when after_wp_load
     */
	public function run( $args, $assoc_args ) {

		$this->setup( $args, $assoc_args );

		$this->update_core();

		$this->update_plugins();

		$this->update_themes();

		$this->update_translations();

		$this->optimize_db();

		$result = $this->get_rollback_record( $this->session );

		if ( $result['count'] > 0 ) {
			WP_CLI::success( "{$result['count']} asset(s) was/were updated ({$result['assets']})" );
		} else {
			WP_CLI::success( "Everything is up to date!" );
		}
	}

	/**
	 * Perform rollback.
	 *
	 * ## OPTIONS
	 *
	 * [--alias=<text>]
     * : Run command against the remote server ( alias listed in wp-cli.yml )
     *
	 * [--file=<text>]
	 * : Path to the report json file ( local or remote )
	 * ---
     * default: updates.json
	 *
	 * [--session=<number>]
	 * : Rollback to specific session number (use `wp update list` to list available dates )
	 * ---
     * default: latest
	 *
	 * [--dry-run]
	 * : dry run option
	 *
	 * ## EXAMPLES
     *
     *     wp update rollback --alias=@prod --file=raport.json --session=2
	 *
	 */
	public function rollback( $args, $assoc_args ) {

		$this->setup( $args, $assoc_args );

		$rollback_session = ( 'latest' === $assoc_args['session'] ) ? $this->session - 1 : absint( $assoc_args['session'] );

		if ( $rollback_session < 1 || $rollback_session >= $this->session ) {
			WP_CLI::error( "No such session in file {$this->file}" );
			return;
		}

		if ( ! $rollaback_record = $this->get_rollback_record( $rollback_session, true ) ) {
			WP_CLI::error( "Nothing to rollback in session $rollback_session" );
			return;
		}

		foreach ( $rollaback_record['rich_data'] as $k => $asset ) {

			if ( 'core' === $asset['type'] ) {

				$cmd = "{$this->alias} core update --version={$asset['old_version']} --force";
				WP_CLI::log( "Run command: wp $cmd" );
				if ( ! $this->dry_run ) {
					$result = WP_CLI::runcommand( $cmd, self::CMD_OPT );
					WP_CLI::log(  "Result: $result" );
				}

			} else {

				// TODO --dry-run option available but not really working ?
				$cmd = "{$this->alias} {$asset['type']} update {$asset['name']} --version={$asset['old_version']}";
				WP_CLI::log( "Run command: wp $cmd" );
				if ( ! $this->dry_run ) {
					$result = WP_CLI::runcommand( $cmd, self::CMD_OPT );
					WP_CLI::log(  "Result: $result" );
				}
			}
		}

		WP_CLI::success( "Rollback completed!" );
	}

	/**
	 * List available updates by session
	 *
	 * ## OPTIONS
	 *
	 * [--file=<text>]
	 * : Path to the report json file ( local or remote )
	 * ---
     * default: updates.json
	 *
	 * ## EXAMPLES
     *
     *     wp update list --alias=@prod --file=../.updates/raport.json
	 *
	 */
	public function list( $args, $assoc_args ) {

		$this->setup( $args, $assoc_args );

		$this->session = $this->current_session();

		if ( $sessions = $this->sessions_list() ) {

			$formatter = new \WP_CLI\Formatter( $assoc_args, [ 'session', 'date', 'count', 'assets' ] );
			$formatter->display_items( $sessions );

		} else {

			WP_CLI::success( "No rollback available (file {$this->file})" );
		}
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function register() {

		WP_CLI::add_command( 'updates run', array( $this, 'run' ) );
		WP_CLI::add_command( 'updates rollback', array( $this, 'rollback' ) );
		WP_CLI::add_command( 'updates list', array( $this, 'list' ) );
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function update_core() {

		$available_version = WP_CLI::runcommand( "{$this->alias} core check-update --field=version --format=json", self::CMD_OPT_JSON );
		if ( is_array( $available_version ) ) {
			$available_version = array_pop( $available_version );
		}

		$result = '';
		if ( $available_version ) { // null or version number
			$current_version = get_bloginfo( 'version' );

			$cmd = "{$this->alias} core update";
			WP_CLI::log( "Run command: wp $cmd" );

			if ( ! $this->dry_run ) { // no dry-run available for core update
				$result = WP_CLI::runcommand( $cmd, self::CMD_OPT );
				WP_CLI::log( "Result: $result" );
			}

			$cmd = "{$this->alias} core update-db {$this->dry_run}";
			WP_CLI::log( "Run command: wp $cmd" );

			$db_result = WP_CLI::runcommand( $cmd, self::CMD_OPT );
			WP_CLI::log( "Result: $db_result" );

			if ( $this->version_exists_in_report( 'core', 'core', $available_version ) ) {
				return;
			}

			$this->updates['core'][] = [
				'date' => $this->date,
				'session' => $this->session,
				'old_version' => $current_version,
				'new_version' => $available_version,
				'result' => $result,
				'db_result' => $db_result
			];
		}

		$this->maybe_save();
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function update_plugins() {

		$cmd = "{$this->alias} plugin update --all --format=json {$this->dry_run}";
		WP_CLI::log( "Run command: wp $cmd" );

		$update_results = WP_CLI::runcommand( $cmd, self::CMD_OPT_JSON );

		if ( is_array( $update_results ) && ! empty( $update_results ) ) {
			foreach ( $update_results as $result ) {

				WP_CLI::log( "Result: {$result['name']} updated to version {$result['new_version']}" );

				$data = WP_CLI::runcommand( "{$this->alias} plugin get {$result['name']} --fields=title,author,status,description --format=json", self::CMD_OPT_JSON );
				if ( $this->version_exists_in_report( 'plugin', $result['name'], $result['new_version'] ) ) {
					continue;
				}

				$this->updates['plugin'][] = array_merge(
					[
						'date' => $this->date,
						'session' => $this->session
					], $result, $data
				);
			}
		}

		$this->maybe_save();
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function update_themes() {

		$cmd = "{$this->alias} theme update --all --format=json {$this->dry_run}";
		WP_CLI::log( "Run command: wp $cmd" );

		$update_results = WP_CLI::runcommand( $cmd, self::CMD_OPT_JSON );

		if ( is_array( $update_results ) && ! empty( $update_results ) ) {
			foreach ( $update_results as $result ) {

				WP_CLI::log( "Result: {$result['name']} updated to version {$result['new_version']}" );

				$data = WP_CLI::runcommand( "{$this->alias} theme get {$result['name']} --fields=title,author,status,description --format=json", self::CMD_OPT_JSON );
				if ( $this->version_exists_in_report( 'theme', $result['name'], $result['new_version'] ) ) {
					continue;
				}
				$this->updates['theme'][] = array_merge(
					[
						'date' => $this->date,
						'session' => $this->session
					], $result, $data
				);
			}
		}

		$this->maybe_save();
	}

	/**
	 * Undocumented function
	 *
	 * no records in json file
	 *
	 * @return void
	 */
	private function update_translations() {

		$cmd = "{$this->alias} language core update {$this->dry_run}";
		WP_CLI::log( "Run command: wp $cmd" );
		$core_translations = WP_CLI::runcommand( $cmd, self::CMD_OPT );
		WP_CLI::log( "Result: $core_translations" );

		$cmd = "{$this->alias} language plugin update --all {$this->dry_run}";
		WP_CLI::log( "Run command: wp $cmd" );
		$plugin_translations = WP_CLI::runcommand( $cmd, self::CMD_OPT );
		WP_CLI::log( "Result: $plugin_translations" );

		$cmd = "{$this->alias} language theme update --all {$this->dry_run}";
		WP_CLI::log( "Run command: wp $cmd" );
		$theme_translations = WP_CLI::runcommand( $cmd, self::CMD_OPT );
		WP_CLI::log( "Result: $theme_translations" );
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function optimize_db() {

		$cmd = "{$this->alias} db optimize";
		WP_CLI::log( "Run command: wp $cmd" );

		if ( ! $this->dry_run ) {
			$result = WP_CLI::runcommand( $cmd, self::CMD_OPT );
			WP_CLI::log( "Result: $result" );
		}
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function current_session() {

		$latest_session = 0;

		if ( is_array( $this->updates ) ) {
			foreach ( $this->updates as $key => $records ) {
				if ( is_array( $records ) ) {
					foreach ( $records as $single_record ) {
						if ( $single_record['session'] > $latest_session ) {
							$latest_session = $single_record['session'];
						}
					}
				}
			}
		}

		return ++$latest_session;
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function sessions_list() {

		if ( $this->session === 1 ) {
			return false;
		}

		$list = [];
		for ( $i=1; $i < $this->session; $i++ ) {
			if ( $record = $this->get_rollback_record( $i ) ) {
				$list[] = $record;
			}
		}

		return $list;
	}

	/**
	 * Undocumented function
	 *
	 * @param [type] $session
	 * @return void
	 */
	private function get_rollback_record( $session, $rich_data=false ) {

		$rollback_record = [
			'session' => $session,
			'date' => '',
			'count' => 0,
			'assets' => ''
		];

		if ( is_array( $this->updates ) ) {
			foreach ( $this->updates as $key => $records ) {
				if ( is_array( $records ) ) {
					foreach ( $records as $single_record ) {
						if ( $single_record['session'] === $session ) {
							$rollback_record['count']++;
							$rollback_record['date'] = $single_record['date'];
							$rollback_record['assets'] .= isset( $single_record['name'] ) ? $single_record['name'] : 'core';
							$rollback_record['assets'] .= ',';

							if ( $rich_data ) {
								$rollback_record['rich_data'][] = [
									'type' => $key,
									'name' => isset( $single_record['name'] ) ? $single_record['name'] : 'core',
									'old_version' => $single_record['old_version'],
									'new_version' => $single_record['new_version']
								];
							}
						}
					}
				}
			}
		}

		if ( $rollback_record['count'] ) {
			$rollback_record['assets'] = trim( $rollback_record['assets'], ',' );
			return $rollback_record;
		}

		return false;
	}

	/**
	 * Undocumented function
	 *
	 * @param [type] $type
	 * @param [type] $name
	 * @param [type] $new_version
	 * @return boolean
	 */
	private function version_exists_in_report( $type, $name, $new_version ) {

		if ( isset( $this->updates[ $type ] ) && is_array( $this->updates[ $type ] ) ) {
			foreach ( $this->updates[ $type ] as $key => $record ) {
				if ( 'core' === $name && $new_version === $record['new_version'] ) {
					return true;
				} elseif ( $name === $record['name'] && $new_version === $record['new_version'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function maybe_save() {

		if ( ! $this->dry_run ) {
			file_put_contents( $this->file, json_encode( $this->updates ) );
		}
	}
}

$updates_command = new Updates_Command();
$updates_command->register();
