<?php
namespace IPS\gdsearch\Price;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Server-side SVG price-history chart with client-side hover tooltips.
 * svg() renders the static chart (line/area/axis + hidden guide+dot elements the JS drives).
 * pointsJson() emits the data + geometry the interface/pricechart.js reads for hover.
 * Always returns a frame, even with no data, so the feature is always visible.
 */
class _Chart
{
	protected const W = 1000;
	protected const H = 220;
	protected const PADL = 64;
	protected const PADR = 24;
	protected const PADT = 20;
	protected const PADB = 32;

	/** @param array<int,array{date:string,price:float}> $series ascending, forward-filled */
	protected static function geometry( array $series ): ?array
	{
		$n = count( $series );
		if ( $n < 2 ) { return null; }

		$plotW = self::W - self::PADL - self::PADR;
		$plotH = self::H - self::PADT - self::PADB;
		$prices = array_column( $series, 'price' );
		$min = min( $prices );
		$max = max( $prices );
		if ( $max <= $min ) { $max = $min + 1; }

		$points = [];
		foreach ( $series as $i => $pt )
		{
			$x = self::PADL + ( $i / ( $n - 1 ) ) * $plotW;
			$y = self::PADT + ( 1 - ( ( (float) $pt['price'] - $min ) / ( $max - $min ) ) ) * $plotH;
			$points[] = [ 'x' => round( $x, 1 ), 'y' => round( $y, 1 ), 'price' => (float) $pt['price'], 'date' => (string) $pt['date'] ];
		}

		return [
			'n' => $n, 'min' => $min, 'max' => $max,
			'plotW' => $plotW, 'plotH' => $plotH,
			'base' => round( self::PADT + $plotH, 1 ),
			'points' => $points,
		];
	}

	public static function svg( array $series ): string
	{
		$g = self::geometry( $series );
		if ( $g === null ) { return self::emptyState(); }

		$w = self::W; $h = self::H; $padL = self::PADL; $padR = self::PADR; $padT = self::PADT;
		$base = $g['base']; $midY = round( $padT + $g['plotH'] / 2, 1 );
		$pts = array_map( static fn( $p ) => $p['x'] . ',' . $p['y'], $g['points'] );
		$first = $g['points'][0]; $cur = $g['points'][ $g['n'] - 1 ];

		$area = 'M ' . $first['x'] . ',' . $base . ' L ' . implode( ' L ', $pts ) . ' L ' . $cur['x'] . ',' . $base . ' Z';
		$line = 'M ' . implode( ' L ', $pts );

		$money = static fn( float $v ): string => htmlspecialchars( '$' . number_format( $v, 2 ), ENT_QUOTES );
		$esc   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );

		$blue = '#1E40AF'; $grid = '#E5E7EB'; $txt = '#6B7280';

		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="Price history" style="width:100%;height:auto;display:block;font-family:Inter,system-ui,sans-serif">';
		/* horizontal gridlines + y labels */
		$svg .= '<line x1="' . $padL . '" y1="' . $padT . '" x2="' . ( $w - $padR ) . '" y2="' . $padT . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<line x1="' . $padL . '" y1="' . $midY . '" x2="' . ( $w - $padR ) . '" y2="' . $midY . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<line x1="' . $padL . '" y1="' . $base . '" x2="' . ( $w - $padR ) . '" y2="' . $base . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $padT + 4, 1 ) . '" text-anchor="end" font-size="13" fill="' . $txt . '">' . $money( $g['max'] ) . '</text>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $midY + 4, 1 ) . '" text-anchor="end" font-size="13" fill="' . $txt . '">' . $money( ( $g['min'] + $g['max'] ) / 2 ) . '</text>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $base + 4, 1 ) . '" text-anchor="end" font-size="13" fill="' . $txt . '">' . $money( $g['min'] ) . '</text>';
		/* area + line */
		$svg .= '<path d="' . $area . '" fill="' . $blue . '" fill-opacity="0.08"/>';
		$svg .= '<path d="' . $line . '" fill="none" stroke="' . $blue . '" stroke-width="2" stroke-linejoin="round"/>';
		/* current point label */
		$svg .= '<circle cx="' . $cur['x'] . '" cy="' . $cur['y'] . '" r="3.5" fill="' . $blue . '"/>';
		$svg .= '<text x="' . $cur['x'] . '" y="' . round( $cur['y'] - 8, 1 ) . '" text-anchor="end" font-size="13" font-weight="700" fill="' . $blue . '">' . $money( $cur['price'] ) . '</text>';
		/* x date labels */
		$svg .= '<text x="' . $padL . '" y="' . ( $h - 8 ) . '" text-anchor="start" font-size="13" fill="' . $txt . '">' . $esc( $first['date'] ) . '</text>';
		$svg .= '<text x="' . ( $w - $padR ) . '" y="' . ( $h - 8 ) . '" text-anchor="end" font-size="13" fill="' . $txt . '">' . $esc( $cur['date'] ) . '</text>';
		/* hover elements (JS toggles/positions these) */
		$svg .= '<line class="gd-pc-guide" x1="0" y1="0" x2="0" y2="0" stroke="' . $blue . '" stroke-width="1" stroke-dasharray="4 3" opacity="0.6" style="display:none"/>';
		$svg .= '<circle class="gd-pc-dot" cx="0" cy="0" r="4.5" fill="' . $blue . '" stroke="#fff" stroke-width="2" style="display:none"/>';
		$svg .= '</svg>';

		return $svg;
	}

	/** JSON the hover JS reads: geometry + every point's viewBox coords, price, date. */
	public static function pointsJson( array $series ): string
	{
		$g = self::geometry( $series );
		$payload = [
			'w'    => self::W,
			'h'    => self::H,
			'padT' => self::PADT,
			'base' => $g['base'] ?? ( self::H - self::PADB ),
			'points' => $g['points'] ?? [],
		];
		return json_encode( $payload, JSON_UNESCAPED_SLASHES );
	}

	protected static function emptyState(): string
	{
		$w = self::W; $h = self::H; $padL = self::PADL; $padR = self::PADR; $padT = self::PADT;
		$plotH = $h - $padT - self::PADB;
		$base = round( $padT + $plotH, 1 );
		$mid  = round( $padT + $plotH / 2, 1 );
		$cx   = round( $padL + ( $w - $padL - $padR ) / 2, 1 );
		$grid = '#E5E7EB'; $txt = '#9CA3AF';

		$rw = round( $w - $padR - $padL, 1 );
		$rh = round( $plotH, 1 );
		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="Price history (no data yet)" style="width:100%;height:auto;display:block;font-family:Inter,system-ui,sans-serif">';
		$svg .= '<rect x="' . $padL . '" y="' . $padT . '" width="' . $rw . '" height="' . $rh . '" rx="8" fill="#F9FAFB" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<text x="' . $cx . '" y="' . round( $mid + 4, 1 ) . '" text-anchor="middle" font-size="14" fill="' . $txt . '">Tracking prices &#8212; history appears here as prices change</text>';
		$svg .= '</svg>';
		return $svg;
	}
}

class Chart extends _Chart {}
