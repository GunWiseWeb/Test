<?php
/**
 * @brief  GD Reviews — Product Reviews application (Stage 1 of 4).
 *
 * A standalone IPS 5 application that hosts native product reviews
 * keyed by catalog UPC, mirroring the Downloads app's review model
 * (a `Reviewable` Content Item + a `\IPS\Content\Review` subclass).
 *
 * STAGE 1 OF 4 — foundation only.
 *   * App skeleton (this class, data/*.json, install.php).
 *   * gdreviews_reviews + gdreviews_products tables (via schema.json
 *     — IPS creates them automatically on fresh install).
 *   * Content Item + Review class stubs under sources/Product/.
 *   * NO front UI, NO admin UI, NO ContentRouter registration.
 *     Stage 2 wires the submission form, Stage 3 wires the display
 *     and the gdsearch product-page tab, Stage 4 does moderation
 *     polish.
 *
 * HARD SAFETY — gd_catalog is READ-ONLY forever, from every stage
 * of this app. Product data (title / image) is pulled live via
 * SELECTs at render time; nothing in gdreviews ever writes to it.
 * gddealer's gd_dealer_ratings and gdcompliance's gd_compliance_review
 * are separate systems and are also never touched by this app.
 */

namespace IPS\gdreviews;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Application extends \IPS\Application
{
	public function get__icon(): string
	{
		return 'star';
	}
}

class Application extends _Application {}
