<?php
/**
 * Escape modes for merge-tag values.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * How a merge-tag value should be escaped when inserted into an HTML email.
 *
 * - HTML: plain-text value, HTML-escaped via esc_html(). The safe default.
 * - RAW: trusted HTML payload, inserted unescaped. Only use when the value
 *   source is server-side and cannot include user input.
 * - URL: link href; passed through esc_url(). Empty when the input is not
 *   a recognisable URL.
 */
enum Escape_Mode: string {

	case HTML = 'html';
	// Only for server-side HTML payloads. Never for user input.
	case RAW = 'raw';
	case URL = 'url';
}
