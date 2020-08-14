<?php

if (!defined('ABSPATH')) die('No direct access allowed');

// handle better utf-8 and unicode encoding
if (function_exists('mb_internal_encoding')) {
	mb_internal_encoding('UTF-8');
}

// must have
ini_set('pcre.backtrack_limit', 5000000);
ini_set('pcre.recursion_limit', 5000000);

require_once WPO_PLUGIN_MAIN_PATH.'/vendor/autoload.php';

// Use PHP Minify - https://github.com/matthiasmullie/minify
use MatthiasMullie\Minify; // phpcs:ignore PHPCompatibility.Keywords.NewKeywords.t_useFound, PHPCompatibility.LanguageConstructs.NewLanguageConstructs.t_ns_separatorFound

if (!class_exists('WP_Optimize_Options')) {
	include_once WPO_PLUGIN_MAIN_PATH.'/includes/class-wp-optimize-options.php';
}

class WP_Optimize_Minify_Functions {

	/**
	 * Detect external or internal scripts
	 *
	 * @param string $src
	 * @return boolean
	 */
	public static function is_local_domain($src) {
		$wpo_minify_options = wp_optimize_minify_config()->get();

		$locations = array(home_url(), site_url(), network_home_url(), network_site_url());
		
		// excluded from cdn because of https://www.chromestatus.com/feature/5718547946799104 (we use document.write to preserve render blocking)
		if (!empty($wpo_minify_options['cdn_url'])
			&& (!$wpo_minify_options['defer_for_pagespeed'] || $wpo_minify_options['cdn_force'])
		) {
			array_push($locations, $wpo_minify_options['cdn_url']);
		}

		// cleanup locations
		$locations = array_filter(array_unique($locations));

		// external or not?
		$ret = false;
		foreach ($locations as $l) {
			$l = preg_replace('/^https?:\/\//i', '', trim($l));
			$l = trim(trim(preg_replace('/^www./', '', $l), '/'));
			if (stripos($src, $l) !== false && false === $ret) {
				$ret = true;
			}
		}

		// response
		return $ret;
	}

	/**
	 * Functions, get hurl info
	 *
	 * @param string $src
	 * @param string $wp_domain
	 * @param string $wp_home
	 * @return string
	 */
	public static function get_hurl($src, $wp_domain, $wp_home) {

		// preserve empty source handles
		$hurl = trim($src);
		if (empty($hurl)) {
			return $hurl;
		}

		// some fixes
		$hurl = str_ireplace(array('&#038;', '&amp;'), '&', $hurl);

		if (is_ssl()) {
			$protocol = 'https://';
		} else {
			$protocol = 'http://';
		}

		// make sure wp_home doesn't have a forward slash
		$wp_home = rtrim($wp_home, '/');

		// apply some filters
		if (substr($hurl, 0, 2) === "//") {
			$hurl = $protocol.ltrim($hurl, "/");
		}//end if
		if (substr($hurl, 0, 4) === "http" && stripos($hurl, $wp_domain) === false) {
			return $hurl;
		}//end if
		if (substr($hurl, 0, 4) !== "http" && stripos($hurl, $wp_domain) !== false) {
			$hurl = $wp_home.'/'.ltrim($hurl, "/");
		}//end if

		// prevent double forward slashes in the middle
		$hurl = str_ireplace('###', '://', str_ireplace('//', '/', str_ireplace('://', '###', $hurl)));

		// consider different wp-content directory
		$proceed = 0;
		if (!empty($wp_home)) {
			$alt_wp_content = basename($wp_home);
			if (substr($hurl, 0, strlen($alt_wp_content)) === $alt_wp_content) {
				$proceed = 1;
			}
		}

		// Get the name of the WP-CONTENT folder. Default is wp-content, but can be changed by the user.
		$wp_content_folder = str_replace(ABSPATH, '', WP_CONTENT_DIR);

		// protocol + home for relative paths
		if ("/".WPINC === substr($hurl, 0, 12)
			|| "/wp-admin" === substr($hurl, 0, 9)
			|| "/$wp_content_folder" === substr($hurl, 0, 11)
			|| 1 == $proceed
		) {
			$hurl = $wp_home.'/'.ltrim($hurl, "/");
		}

		// make sure there is a protocol prefix as required
		$hurl = $protocol.preg_replace('/^https?:\/\//i', '', $hurl); // enforce protocol

		// no query strings
		if (stripos($hurl, '.js?v') !== false) {
			$hurl = stristr($hurl, '.js?v', true).'.js'; // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters.stristr_before_needleFound
		}//end if
		if (stripos($hurl, '.css?v') !== false) {
			$hurl = stristr($hurl, '.css?v', true).'.css'; // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters.stristr_before_needleFound
		}//end if

		return $hurl;
	}

	/**
	 * Check if it's an internal url or not
	 *
	 * @param string $hurl
	 * @param string $wp_home
	 * @param mixed  $noxtra
	 * @return boolean
	 */
	public static function internal_url($hurl, $wp_home, $noxtra = null) {
		$wpo_minify_options = wp_optimize_minify_config()->get();

		if (substr($hurl, 0, strlen($wp_home)) === $wp_home) {
			return true;
		}
		if (stripos($hurl, $wp_home) !== false) {
			return true;
		}
		if (isset($_SERVER['HTTP_HOST']) && stripos($hurl, preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) !== false) {
			return true;
		}
		if (isset($_SERVER['SERVER_NAME']) && stripos($hurl, preg_replace('/:\d+$/', '', $_SERVER['SERVER_NAME'])) !== false) {
			return true;
		}
		if (isset($_SERVER['SERVER_ADDR']) && stripos($hurl, preg_replace('/:\d+$/', '', $_SERVER['SERVER_ADDR'])) !== false) {
			return true;
		}

		// allow specific external urls to be merged
		if (null === $noxtra) {
			$merge_allowed_urls = array_map('trim', explode("\n", $wpo_minify_options['merge_allowed_urls']));
			if (is_array($merge_allowed_urls) && strlen(implode($merge_allowed_urls)) > 0) {
				foreach ($merge_allowed_urls as $e) {
					if (stripos($hurl, $e) !== false && !empty($e)) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Case-insensitive in_array() wrapper
	 *
	 * @param string $hurl
	 * @param array  $ignore
	 * @return boolean
	 */
	public static function in_arrayi($hurl, $ignore) {
		$hurl = preg_replace('/^https?:\/\//i', '//', $hurl); // better compatibility
		$hurl = strtok(urldecode(rawurldecode($hurl)), '?'); // no query string, decode entities
		
		if (!empty($hurl) && is_array($ignore)) {
			foreach ($ignore as $i) {
				$i = preg_replace('/^https?:\/\//i', '//', $i); // better compatibility
				$i = strtok(urldecode(rawurldecode($i)), '?'); // no query string, decode entities
				$i = trim(trim(trim(rtrim($i, '/')), '*')); // wildcard char removal
				if (false !== stripos($hurl, $i)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Better compatibility urls + fix w3.org NamespaceAndDTDIdentifiers
	 *
	 * @param string $code
	 * @return string
	 * */
	private static function compat_urls($code) {
		$wpo_minify_options = wp_optimize_minify_config()->get();
		$default_protocol = $wpo_minify_options['default_protocol'];
		if ('dynamic' == $default_protocol) {
			if ((isset($_SERVER['HTTPS']) && ('on' == $_SERVER['HTTPS'] || 1 == $_SERVER['HTTPS']))
				|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' == $_SERVER['HTTP_X_FORWARDED_PROTO'])
			) {
				$default_protocol = 'https://';
			} else {
				$default_protocol = 'http://';
			}
		} else {
			$default_protocol = $default_protocol.'://';
		}
		$code = preg_replace('/^https?:\/\//i', $default_protocol, $code);
		$code = str_ireplace($default_protocol.'www.w3.org', 'http://www.w3.org', $code);
		return $code;
	}

	/**
	 * Minify css string with PHP Minify
	 *
	 * @param string $css
	 * @return string
	 */
	public static function minify_css_string($css) {
		$minifier = new Minify\CSS($css); // phpcs:ignore PHPCompatibility.LanguageConstructs.NewLanguageConstructs.t_ns_separatorFound
		$minifier->setMaxImportSize(15); // [css only] embed assets up to 15 Kb (default 5Kb) - processes gif, png, jpg, jpeg, svg & woff
		$min = $minifier->minify();
		if (false !== $min) {
			return self::compat_urls($min);
		}
		return self::compat_urls($css);
	}

	/**
	 * Find if we are running windows
	 *
	 * @return boolean
	 */
	public static function server_is_windows() {
		// PHP 7.2.0+
		if (defined('PHP_OS_FAMILY')) {
		 // phpcs:disable
		 if (strtolower(PHP_OS_FAMILY) == 'windows') return true;
		 // phpcs:enable
		}
		if (function_exists('php_uname')) {
			$os = php_uname('s');
			if (stripos($os, 'Windows') !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Minify js on demand (one file at one time, for compatibility)
	 *
	 * @param string  $url
	 * @param string  $js
	 * @param boolean $enable_js_minification
	 * @return string
	 */
	public static function get_js($url, $js, $enable_js_minification) {
		$wpo_minify_options = wp_optimize_minify_config()->get();

		// exclude minification on already minified files + jquery (because minification might break those)
		$excl = array('jquery.js', '.min.js', '-min.js', '/uploads/fusion-scripts/', '/min/', '.packed.js', '/includes/builder/scripts/');
		foreach ($excl as $e) {
			if (stripos(basename($url), $e) !== false) {
				$enable_js_minification = false;
				break;
			}
		}
		// remove BOM
		$js = self::remove_utf8_bom($js);

		// minify JS
		if ($enable_js_minification) {
			$js = self::minify_js_string($js);
		} else {
			$js = self::compat_urls($js);
		}

		// Remove source mapping files
		$js = preg_replace('/(\/\/\s*[#]\s*sourceMappingURL\s*[=]\s*)(.+)\s*/ui', '', $js);

		// needed when merging js files
		$js = trim($js);
		if (substr($js, -1) != ';') {
			$js = $js.';';
		}
		if ($wpo_minify_options['debug']) {
			$js = '/* info: ' . $url . ' */' . "\n" . $js;
		}

		// return html
		return $js . PHP_EOL;
	}

	/**
	 * Minify JS string with PHP Minify or YUI Compressors
	 *
	 * @param string $js
	 * @return string
	 */
	public static function minify_js_string($js) {
		// PHP Minify from https://github.com/matthiasmullie/minify
		$minifier = new Minify\JS($js); // phpcs:ignore PHPCompatibility.LanguageConstructs.NewLanguageConstructs.t_ns_separatorFound
		$min = $minifier->minify();
		if (false !== $min && (strlen(trim($js)) == strlen(trim($min)) || strlen(trim($min)) > 0)) {
			return self::compat_urls($min);
		}
		
		// if we are here, something went  wrong and minification didn't work
		$js = "\n/*! wpo_min: Minification of the following section failed, so it has been merged instead. */\n".$js;
		return self::compat_urls($js);
	}

	/**
	 * Functions, minify html
	 *
	 * @param string $html
	 * @return string
	 */
	public static function minify_html($html) {
		$minify_css = wp_optimize_minify_config()->get('enable_css_minification');
		$minify_js = wp_optimize_minify_config()->get('enable_js_minification');
		$options = array();
		if ($minify_css && apply_filters('wpo_minify_inline_css', true)) {
			$options['cssMinifier'] = array('WP_Optimize_Minify_Functions', 'minify_css_string');
		}
		if ($minify_js && apply_filters('wpo_minify_inline_js', true)) {
			$options['jsMinifier'] = array('WP_Optimize_Minify_Functions', 'minify_js_string');
		}
		return Minify_HTML::minify($html, $options);
	}

	/**
	 * Functions to minify HTML
	 *
	 * @param string $html
	 * @return string
	 */
	public static function html_compression_finish($html) {
		return self::minify_html($html);
	}

	/**
	 * Start the compression
	 *
	 * @return void
	 */
	public static function html_compression_start() {
		if (self::exclude_contents() == true) {
			return;
		}
		ob_start(array(__CLASS__, 'html_compression_finish'));
	}

	/**
	 * Remove default HTTP headers
	 *
	 * @return void
	 */
	public static function remove_redundant_shortlink() {
		remove_action('wp_head', 'wp_shortlink_wp_head', 10);
		remove_action('template_redirect', 'wp_shortlink_header', 11);
	}

	/**
	 * Minify css on demand (one file at one time, for compatibility)
	 *
	 * @param string  $url
	 * @param string  $css
	 * @param boolean $enable_css_minification
	 * @return string
	 */
	public static function get_css($url, $css, $enable_css_minification) {
		$wpo_minify_options = wp_optimize_minify_config()->get();
		
		// remove BOM
		$css = self::remove_utf8_bom($css);

		// fix url paths
		if (!empty($url)) {
			$css = self::make_css_urls_absolute($css, $url);
		}

		$css = str_ireplace('@charset "UTF-8";', '', $css);

		// remove query strings from fonts (for better seo, but add a small cache buster based on most recent updates)
		// last update or zero
		$cache_time = $wpo_minify_options['last-cache-update'];
		// fonts cache buster
		$css = preg_replace('/(.eot|.woff2|.woff|.ttf)+[?+](.+?)(\)|\'|\")/ui', "$1"."#".$cache_time."$3", $css);
		// Remove Sourcemappingurls
		$css = preg_replace('/(\/\*\s*[#]\s*sourceMappingURL\s*[=]\s*)(.[^*]+)\s*\*\//ui', '', $css);
		// If @import is found, process it/them
		if (false !== strpos($css, '@import')) $css = self::replace_css_import($css, $url);

		// minify CSS
		if ($enable_css_minification) {
			$css = self::minify_css_string($css);
		} else {
			$css = self::compat_urls($css);
		}

		// cdn urls
		$cdn_url = $wpo_minify_options['cdn_url'];
		if (!empty($cdn_url)) {
			$wp_domain = trim(preg_replace('/^https?:\/\//i', '', trim(site_url(), '/')));
			$cdn_url = trim(trim(preg_replace('/^https?:\/\//i', '', trim($cdn_url, '/'))), '/');
			$css = str_ireplace($wp_domain, $cdn_url, $css);
		}

		// add css comment
		$css = trim($css);
		if ($wpo_minify_options['debug']) {
			$css = '/* info: ' . $url . ' */' . "\n" . trim($css);
		}

		// return html
		return $css;
	}

	/**
	 * Adds full path to relative url() rules
	 *
	 * @param string $css - The CSS to process
	 * @param string $url - The URL or the CSS being processed
	 * @return string
	 */
	public static function make_css_urls_absolute($css, $url) {
		$matches = array();
		preg_match_all("/url\(\s*['\"]?(?!data:)(?!http)(?![\/'\"])(.+?)['\"]?\s*\)/ui", $css, $matches);
		foreach ($matches[1] as $a) {
			$b = trim($a);
			if ($b != $a) {
				$css = str_replace($a, $b, $css);
			}
		}
		return preg_replace("/url\(\s*['\"]?(?!data:)(?!http)(?![\/'\"])(.+?)['\"]?\s*\)/ui", "url(".dirname($url)."/$1)", $css);
	}

	/**
	 * Include @import[ed] files - The @import statement can only be used at the top of a file, which breaks when merging everything.
	 *
	 * @param string $css      - The original CSS containing the @import statement
	 * @param string $file_url - The original CSS' URL
	 * @return string
	 */
	public static function replace_css_import($css, $file_url) {
		$remove_print_mediatypes = wp_optimize_minify_config()->get('remove_print_mediatypes');
		$debug = wp_optimize_minify_config()->get('debug');
		return preg_replace_callback('/@import(.*);?/mi', function($matches) use ($file_url, $remove_print_mediatypes, $debug) { // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewClosure.Found
			// @import contains url()
			if (preg_match('/url\s*\((.[^\)]*)[\)*?](.*);/', $matches[1], $url_matches)) {
				$url = trim(str_replace(array('"', "'"), '', $url_matches[1]));
				$media_query = trim($url_matches[2]);
			// @import uses quotes only
			} elseif (preg_match('/["\'](.*)["\'](.*);/', $matches[1], $no_url_matches)) {
				$url = trim($no_url_matches[1]);
				$media_query = trim($no_url_matches[2]);
			}
			
			// If $media_query contains print, and $remove_print_mediatypes is true, return empty string
			if ($remove_print_mediatypes && false !== strpos($media_query, 'print') && apply_filters('wpo_minfy_remove_print_mediatypes_import', true, $url, $media_query, $matches[0], $file_url)) return ($debug ? '/*! Info: the import of "'.$url.'" was removed because the setting remove_print_mediatypes is enabled. */' : '');

			$purl = parse_url($url);
			// If there's no host, the url is relative to $file_url, so prepend with the base url.
			if (!isset($purl['host'])) {
				$url = dirname($file_url).'/'.$url;
			}

			// Download @import
			$asset_content = WP_Optimize_Minify_Functions::get_asset_content($url);
			$content = $asset_content['content'];
			
			if (!$content) return '';

			// Fix the URLs
			$content = WP_Optimize_Minify_Functions::make_css_urls_absolute($content, $url);

			if ($media_query) {
				// Wrap the code with the media query
				$content = "@media $media_query {\n$content\n}";
			}

			if ($debug) {
				$content = "/*! CSS import Information: code imported from $url */\n$content\n/*! END CSS import Information */";
			}
			
			// If the code contains its own @import, recursively include it.
			if (false !== strpos($content, '@import')) {
				return WP_Optimize_Minify_Functions::replace_css_import($content, $url);
			}

			return $content;
		}, $css);
	}

	/**
	 * Download and cache css and js files
	 *
	 * @param string  $hurl
	 * @param string  $inline
	 * @param boolean $enable_minification
	 * @param string  $type
	 * @param string  $handle
	 * @return boolean|string
	 */
	public static function download_and_minify($hurl, $inline, $enable_minification, $type, $handle) {
		$wp_home = site_url();

		// must have
		if (is_null($hurl) || empty($hurl)) {
			return false;
		}
		if (!in_array($type, array('js', 'css'))) {
			return false;
		}

		$wpo_minify_options = wp_optimize_minify_config()->get();

		// filters and defaults
		$print_url = str_ireplace(array(site_url(), home_url(), 'http:', 'https:'), '', $hurl);

		$log = array(
			'url' => $print_url,
		);

		// defaults
		if (false != $enable_minification) {
			$enable_minification = true;
		}
		if (is_null($inline) || empty($inline)) {
			$inline = '';
		}
		$print_handle = '';
		if (is_null($handle) || empty($handle)) {
			$handle = '';
		} else {
			$print_handle = "[$handle]";
		}

		// debug request
		$dreq = array(
			'hurl' => $hurl,
			'inline' => $inline,
			'enable_minification' => $enable_minification,
			'type' => $type,
			'handle' => $handle
		);

		$asset_content = self::get_asset_content($hurl);
		$code = $asset_content['content'];

		// If $code is empty:
		if (!$code) {
			$log['success'] = false;
			if ($wpo_minify_options['debug']) {
				$log['debug'] = "$print_handle failed. Tried wp_remote_get and local file_get_contents.";
			}
			$return = array('request' => $dreq, 'log' => $log, 'code' => '', 'status' => false);
			return json_encode($return);
		}

		if ('js' == $type) {
			$code = self::get_js($hurl, $code, $enable_minification);
		} else {
			$code = self::get_css($hurl, $code.$inline, $enable_minification);
		}

		// log, save and return
		if ($wpo_minify_options['debug']) {
			$log['debug'] = $print_handle.' was '.('local' === $asset_content['method'] ? 'opened' : 'fetched').' from '.$hurl;
		}
		$log['success'] = true;
		$return = array('request' => $dreq, 'log' => $log, 'code' => $code, 'status' => true);
		return json_encode($return);
	}

	/**
	 * Get the content of an asset, wether local or remote
	 *
	 * @param string $url
	 * @return array
	 */
	public static function get_asset_content($url) {

		$wp_home = site_url();
		$wp_domain = parse_url($wp_home, PHP_URL_HOST);
		// If the server is not Windows, and the file is local.
		if (self::server_is_windows() === false && stripos($url, $wp_domain) !== false) {
			// default
			$f = str_ireplace(rtrim($wp_home, '/'), rtrim(ABSPATH, '/'), $url);
			clearstatcache();
			if (file_exists($f)) {
				$content = file_get_contents($f);
				// check for php code, skip if found
				if ("<?php" != strtolower(substr($content, 0, 5)) && stripos($content, "<?php") === false) {
					return array('content' => $content, 'method' => 'local');
				}
			}
			
			// failover when home_url != site_url
			$nhurl = str_ireplace(site_url(), home_url(), $url);
			$f = str_ireplace(rtrim($wp_home, '/'), rtrim(ABSPATH, '/'), $nhurl);
			clearstatcache();
			if (file_exists($f)) {
				$content = file_get_contents($f);
				// check for php code, skip if found
				if (strtolower(substr($content, 0, 5)) != "<?php" && stripos($content, "<?php") === false) {
					return array('content' => $content, 'method' => 'local');
				}
			}
		}


		// else, fallback to remote urls (or windows)
		$content = self::download_remote($url);
		if (false !== $content
			&& !empty($content)
			&& strtolower(substr($content, 0, 9)) != "<!doctype"
		) {
			// check if we got HTML instead of js or css code
			return array('content' => $content, 'method' => 'remote');
		}


		// fallback when home_url != site_url
		if (stripos($url, $wp_domain) !== false && home_url() != site_url()) {
			$nhurl = str_ireplace(site_url(), home_url(), $url);
			$content = self::download_remote($nhurl);
			if (false !== $content && !empty($content) && '<!doctype' != strtolower(substr($content, 0, 9))) {
				return array('content' => $content, 'method' => 'remote');
			}
		}
		
		return array('content' => '', 'method' => 'none');
	}

	/**
	 * Remove emoji support
	 *
	 * @return void
	 */
	public static function disable_wp_emojicons() {
		remove_action('wp_head', 'print_emoji_detection_script', 7);
		remove_action('admin_print_scripts', 'print_emoji_detection_script');
		remove_action('wp_print_styles', 'print_emoji_styles');
		remove_action('admin_print_styles', 'print_emoji_styles');
		remove_filter('the_content_feed', 'wp_staticize_emoji');
		remove_filter('comment_text_rss', 'wp_staticize_emoji');
		remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
	}

	/**
	 * Remove from tinymce
	 *
	 * @return array
	 */
	public static function disable_emojis_tinymce($plugins) {
		if (is_array($plugins)) {
			return array_diff($plugins, array('wpemoji'));
		} else {
			return array();
		}
	}

	/**
	 * Remove UTF8 BOM
	 *
	 * @param string $string
	 * @return string
	 */
	public static function remove_utf8_bom($string) {
		$bom = pack('H*', 'EFBBBF');
		$string = preg_replace("/^$bom/ui", '', $string);
		return $string;
	}

	/**
	 * Remove query string from static css files
	 *
	 * @param string $src
	 * @return string
	 */
	public static function remove_cssjs_ver($src) {
		if (stripos($src, '?ver=')) {
			$src = remove_query_arg('ver', $src);
		}
		return $src;
	}

	/**
	 * Rewrite cache files to http, https or dynamic
	 *
	 * @param string $url
	 * @return string
	 */
	public static function get_protocol($url) {
		$wp_domain = trim(preg_replace('/^https?:\/\//i', '', trim(site_url(), '/')));
		$wpo_minify_options = wp_optimize_minify_config()->get();
		$default_protocol = $wpo_minify_options['default_protocol'];

		$url = ltrim(preg_replace('/^https?:\/\//i', '', $url), '/'); // better compatibility

		// cdn support
		$cdn_url = $wpo_minify_options['cdn_url'];
		$cdn_url = trim(trim(preg_replace('/^https?:\/\//i', '', trim($cdn_url, '/'))), '/');
		
		// process cdn rewrite
		if (!empty($cdn_url) && self::is_local_domain($url) !== false) {
			
			// for js files, we need to consider thew defer for insights option
			if (substr($url, -3) == '.js') {
				
				if (!$wpo_minify_options['defer_for_pagespeed']
					|| $wpo_minify_options['cdn_force']
				) {
					$url = str_ireplace($wp_domain, $cdn_url, $url);
				}
			} else {
				$url = str_ireplace($wp_domain, $cdn_url, $url);
			}
		}

		// enforce protocol if needed
		if ('dynamic' == $default_protocol) {
			if ((isset($_SERVER['HTTPS']) && ('on' == $_SERVER['HTTPS'] || 1 == $_SERVER['HTTPS']))
				|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' == $_SERVER['HTTP_X_FORWARDED_PROTO'])
			) {
				$default_protocol = 'https://';
			} else {
				$default_protocol = 'http://';
			}
		} else {
			$default_protocol = $default_protocol.'://';
		}
		
		// return
		return $default_protocol . $url;
	}

	/**
	 * Exclude processing from some pages / posts / contents
	 *
	 * @return boolean
	 */
	public static function exclude_contents() {
		// prevent execution for specific urls
		if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
			$disable_on_url = array_filter(array_map('trim', explode("\n", get_option('wpo_min_disable_on_url', ''))));
			foreach ($disable_on_url as $url) {
				if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) == $url) {
					return true;
				}
			}
		}
	
		// for compatibility, let's always skip the checkout page
		if (function_exists('is_checkout') && is_checkout() === true) {
			return true;
		}

		// exclude processing here
		if (is_feed()
			|| is_admin()
			|| is_preview()
			|| (function_exists('is_customize_preview') && is_customize_preview())
			|| (defined('DOING_AJAX') && DOING_AJAX)
			|| (function_exists('wp_doing_ajax') && wp_doing_ajax())
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			|| (defined('WP_BLOG_ADMIN') && WP_BLOG_ADMIN)
			|| (defined('WP_NETWORK_ADMIN') && WP_NETWORK_ADMIN)
			|| (defined('WP_INSTALLING') && WP_INSTALLING)
			|| (defined('WP_IMPORTING') && WP_IMPORTING)
			|| (defined('WP_REPAIRING') && WP_REPAIRING)
			|| (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
			|| (defined('SHORTINIT') && SHORTINIT)
			|| (defined('REST_REQUEST') && REST_REQUEST)
			|| (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'])
			|| (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
			|| (isset($_SERVER['REQUEST_URI']) && (strtolower(substr($_SERVER['REQUEST_URI'], -4)) == '.txt' || strtolower(substr($_SERVER['REQUEST_URI'], -4)) == '.xml'))
		) {
			return true;
		}

		// Thrive plugins and other post_types
		$arr = array('tve_form_type', 'tve_lead_shortcode', 'tqb_splash');
		foreach ($arr as $a) {
			if (isset($_GET['post_type']) && $a === $_GET['post_type']) {
				return true;
			}
		}

		// Thrive architect
		if (isset($_GET['tve']) && 'true' === $_GET['tve']) return true;
		

		if (is_array($_GET)) {
			foreach ($_GET as $k => $v) {
				if (is_string($v) && is_string($k)) {
					if (stripos($k, 'elementor') !== false || stripos($v, 'elementor') !== false) {
						return true;
					}
				}
			}
		}

		// Other _GET parameters
		if (is_array($_GET)) {
			$get_params = array_keys($_GET);
			$excluded_params = array(
				// customizer preview, visual composer
				'customize_theme',
				'preview_id',
				'preview',
				// Elementor
				'elementor-preview',
				// Divi builder
				'et_fb',
				'PageSpeed',
			);
			return (bool) count(array_intersect($excluded_params, $get_params));
		}

		/**
		 * Wether to exclude the content or not from the minifying process.
		 */
		return apply_filters('wpo_minify_exclude_contents', false);
	}

	/**
	 * Know files that should always be ignored
	 *
	 * @param array $ignore
	 * @return array
	 */
	public static function default_ignore($ignore) {
		if (is_array($ignore)) {
			$wpo_minify_options = wp_optimize_minify_config()->get();
			$ignore_list = array_map('trim', explode("\n", trim($wpo_minify_options['ignore_list'])));
			$master_ignore = array_merge(array_map('strtolower', $ignore), array_map('strtolower', $ignore_list));
			return array_unique($master_ignore);
		} else {
			return $ignore;
		}
	}

	/**
	 * IE only files that should always be ignored, without incrementing our groups
	 *
	 * @param string $url
	 * @return boolean
	 */
	public static function ie_blacklist($url) {
		$wpo_minify_options = wp_optimize_minify_config()->get();

		// from the database
		$blacklist = array_map('trim', explode("\n", trim($wpo_minify_options['blacklist'])));
		// must have
		$blacklist[] = '/wpo_min/cache/';
		
		// is the url on our list and return
		$res = self::in_arrayi($url, $blacklist);
		if (true == $res) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Download function with fallback
	 *
	 * @param string $url
	 * @return boolean
	 */
	public static function download_remote($url) {
		
		$args = array(
			// info (needed for google fonts woff files + hinted fonts) as well as to bypass some security filters
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2486.0 Safari/537.36 Edge/13.10586',
			'timeout' => 7,
			'httpversion' => '1.1'
		);

		// fetch via wordpress functions
		$response = wp_remote_get(
			$url,
			/**
			 * Filters the arguments passed to wp_remote_get when downloading the scripts.
			 *
			 * @param array  $args - The arguments filtered
			 * @param string $url  - The URL of the downloaded asset
			 * @return array
			 */
			apply_filters('wpo_minify_download_request_args', $args, $url)
		);

		$res_code = wp_remote_retrieve_response_code($response);
		if ('200' == $res_code) {
			$data = wp_remote_retrieve_body($response);
			if (strlen($data) > 1) {
				return $data;
			}
		}
		
		// verify
		if (!isset($res_code) || empty($res_code) || false == $res_code || is_null($res_code)) {
			return false;
		}
		
		// stop here, error 4xx or 5xx
		if ('4' == $res_code[0] || '5' == $res_code[0]) {
			return false;
		}
		
		// fallback fail
		return false;
	}
	
	/**
	 * Turn a byte count into a human-readable text output
	 *
	 * @param integer $byte_count - byte count
	 *
	 * @return string
	 */
	public static function format_filesize($byte_count) {
		if (is_numeric($byte_count)) {
			if ($byte_count / 1099511627776 > 1) {
				return number_format_i18n($byte_count/1099511627776, 1).' '.__('TiB', 'wp-optimize');
			} elseif ($byte_count / 1073741824 > 1) {
				return number_format_i18n($byte_count/1073741824, 1).' '.__('GiB', 'wp-optimize');
			} elseif ($byte_count / 1048576 > 1) {
				return number_format_i18n($byte_count/1048576, 1).' '.__('MiB', 'wp-optimize');
			} elseif ($byte_count / 1024 > 1) {
				return number_format_i18n($byte_count/1024, 1).' '.__('KiB', 'wp-optimize');
			} elseif ($byte_count > 1) {
				return number_format_i18n($byte_count, 0).' '.__('bytes', 'wp-optimize');
			} else {
				return __('N/A', 'wp-optimize');
			}
		} else {
			return __('N/A', 'wp-optimize');
		}
	}

	/**
	 * Prepares the merged JavaScript
	 *
	 * @param string $script
	 * @param string $merged_url
	 * @return string
	 */
	public static function prepare_merged_js($script, $merged_url) {
		$enable_js_trycatch = wp_optimize_minify_config()->get('enable_js_trycatch');
		if ($enable_js_trycatch) {
			return 'try{'."\n".$script."\n".'}'."\n".'catch(e){console.error("WP-Optimize Minify: An error has occurred in the minified code. \n\n- Original script: '.esc_attr($merged_url).'\n- Error message: "+ e.message);}'."\n";
		}
		return $script;
	}
}
